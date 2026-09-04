<?php

namespace App\Services\Accounting;

use App\Enums\PaymentStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\TransactionImmutableException;
use App\Exceptions\Accounting\TransactionPayloadMismatchException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The workflow around a transaction: writing one, saving it for later, posting
 * it, correcting it.
 *
 * Deliberately separate from {@see PostingEngine}. The engine answers "are
 * these entries valid, and how are they written"; this answers "what is the
 * user allowed to do with this transaction right now". Keeping the ledger's
 * correctness away from the application's workflow means a change to one cannot
 * quietly weaken the other.
 */
class TransactionService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly TenantRepositoryInterface $tenants,
        private readonly BillService $bills,
        private readonly PostingEngine $engine,
        private readonly TenantContext $context,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->transactions->paginate($this->resolveOverdueCutoff($filters), $perPage);

        return $this->withSettlements($page);
    }

    public function find(int $id): Transaction
    {
        $transaction = $this->transactions->findWithEntries($id)
            ?? throw new ResourceNotFoundException('Transaction', $id);

        return $this->withSettlement($transaction);
    }

    /**
     * The document a client already named, if it wrote one — M17.
     *
     * Null where the reference has not been seen, which is the ordinary case.
     * {@see create()} consults this itself, so no caller needs it merely to
     * avoid a duplicate; it is public for the callers that have their *own*
     * refusals to get out of the way first.
     *
     * M19's job billing is the one that needs it. The first attempt marks every
     * part as billed, so a retry would be refused for having nothing left to bill
     * before it ever reached the duplicate check — which is exactly backwards for
     * the clerk who tapped Save twice.
     */
    public function findByClientRef(string $clientRef): ?Transaction
    {
        $existing = $this->transactions->findByClientRef($clientRef);

        return $existing === null ? null : $this->withSettlement($existing);
    }

    /* ---------------------------------------------------------------------
     | Per-invoice money — M16
     |-------------------------------------------------------------------- */

    /**
     * Attach each bill's paid/due/status to the rows of a page, in one query.
     *
     * Attached to the model rather than computed in {@see \App\Http\Resources\TransactionResource}
     * for the reason a running balance is: it depends on rows the serialiser
     * cannot see, and a resource that went looking for them would issue a query
     * per row of every listing in the application.
     *
     * @param  LengthAwarePaginator<int, Transaction>  $page
     * @return LengthAwarePaginator<int, Transaction>
     */
    private function withSettlements(LengthAwarePaginator $page): LengthAwarePaginator
    {
        $positions = $this->bills->settlementsFor($page->items());

        foreach ($page as $transaction) {
            $transaction->settlement = $positions[(int) $transaction->id] ?? null;
        }

        return $page;
    }

    private function withSettlement(Transaction $transaction): Transaction
    {
        $transaction->settlement = $this->bills->settlementFor($transaction);

        return $transaction;
    }

    /**
     * Turn the workshop's payment terms into the date a bill has to be dated on
     * or before to count as overdue.
     *
     * Resolved once, here, rather than inside the query: the repository would
     * otherwise read the same settings row for every row it returned, and it has
     * no business knowing what a tenant is. Absent where the workshop has set no
     * terms, in which case nothing is overdue — see {@see \App\Models\Tenant::dueDateFor()}.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function resolveOverdueCutoff(array $filters): array
    {
        // Only the three statuses that have to know where the line falls. `paid`
        // is decided by arithmetic alone, and the outstanding toggle spans all
        // four — neither needs the settings row read.
        $needsCutoff = in_array($filters['payment_status'] ?? null, [
            PaymentStatus::Overdue->value,
            PaymentStatus::Partial->value,
            PaymentStatus::Unpaid->value,
        ], true);

        if (! $needsCutoff) {
            return $filters;
        }

        $tenant = $this->tenants->findById($this->context->requireTenant('listing transactions'));
        $days = $tenant?->payment_due_days;

        $filters['overdue_on_or_before'] = $days === null
            ? null
            : CarbonImmutable::now($tenant?->timezone ?? config('app.timezone'))
                ->startOfDay()
                ->subDays($days)
                ->toDateString();

        return $filters;
    }

    /**
     * The type and status breakdown behind a screen's tab badges.
     *
     * @return array{types: array<string, int>, statuses: array<string, int>}
     */
    public function counts(): array
    {
        return $this->transactions->countsByTypeAndStatus();
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * Create a transaction, either posted outright or parked as a draft.
     *
     * The client says which, explicitly. There is no "save and maybe post"
     * default: committing to the ledger is the consequential act in this
     * product, and it should never be something that happened by omission.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(TransactionType $type, array $payload, ?User $actor = null): Transaction
    {
        $input = $this->inputFrom($payload);
        $clientRef = $input['client_ref'] ?? null;

        // The brief's §28, and the reason it is checked here rather than in a
        // controller: every entry point for a document passes through this
        // method, and a guard in one of them says nothing about the other five.
        //
        // A repeat is *returned*, not refused. The clerk who tapped Save twice
        // did not do anything wrong and does not need an error — they need the
        // bill. `wasRecentlyCreated` is what a caller reads to tell the two
        // apart, so the second attempt answers 200 rather than 201.
        if ($clientRef !== null) {
            $existing = $this->transactions->findByClientRef($clientRef);

            if ($existing !== null) {
                return $this->withSettlement($existing);
            }
        }

        try {
            // Composed and posted inside one database transaction, because for a
            // type that moves stock the cost of an issue is read while composing
            // and written a moment later — see PostingEngine::postComposed().
            // Drafting needs no such wrapper: a draft values nothing,
            // deliberately.
            return ($payload['post'] ?? false)
                ? $this->engine->postComposed($type, $input, $actor)
                : $this->engine->draft($this->engine->compose($type, $input), $actor);
        } catch (UniqueConstraintViolationException $collision) {
            // The two taps arrived close enough that both got past the lookup
            // above. The index caught the second, and the first has committed by
            // the time it did — so the answer exists and the caller gets it.
            //
            // Re-thrown where it was some *other* unique index, because a
            // duplicate document number is a real failure and swallowing it here
            // would hide the one thing M16's locking exists to make impossible.
            $existing = $clientRef === null ? null : $this->transactions->findByClientRef($clientRef);

            if ($existing === null) {
                throw $collision;
            }

            return $this->withSettlement($existing);
        }
    }

    /**
     * The compose payload, from the request payload.
     *
     * Everything the caller sent is passed through rather than a fixed list of
     * keys being picked out, because each template reads only the vocabulary it
     * understands: a journal reads `lines`, a settlement reads `payments`, a
     * stock adjustment reads `adjustments`, a bill reads both `lines` and
     * `payments`. Branching on the type here would mean this method had to be
     * edited for every module from M8 to M11 — and the one that got forgotten
     * would present as a field silently doing nothing.
     *
     * `post` is stripped: it is an instruction to this service, not part of the
     * document, and leaving it in would put it on a draft's stored payload where
     * it would look like something to obey.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function inputFrom(array $payload, ?Transaction $draft = null): array
    {
        $input = array_diff_key($payload, array_flip(['post']));

        $input['date'] = $payload['date'] ?? $draft?->date;
        $input['source'] = $draft?->source ?? TransactionSource::Manual;

        return $input;
    }

    /**
     * Rewrite a draft. Anything already in the books is refused here and again
     * in the model, which is the guard that cannot be bypassed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(int $id, array $payload): Transaction
    {
        $transaction = $this->find($id);

        if (! $transaction->isDraft()) {
            throw TransactionImmutableException::posted($transaction->id);
        }

        $this->assertPayloadFitsType($transaction, $payload);

        // Everything the draft already holds, with the caller's changes over the
        // top. A PATCH that only moves the date keeps the rest — and for a
        // derived type it is the stored *request* that carries forward, so the
        // bill is still re-priced when it is finally posted.
        $input = $this->inputFrom(array_merge(
            [
                'date' => $transaction->date,
                'notes' => $transaction->notes,
                'lines' => $transaction->draft_lines ?? [],
                'payments' => $transaction->draft_payments ?? [],
                // Absent means unchanged, and an explicit null detaches the
                // party — which is why the merge below is by key rather than a
                // `??` chain, and is the same rule the other optional fields
                // follow.
                'party_id' => $transaction->party_id,
            ],
            $transaction->draft_payload ?? [],
            $payload,
        ), $transaction);

        return $this->engine->updateDraft($transaction, $this->engine->compose($transaction->type, $input));
    }

    /**
     * Refuse an edit written in the wrong vocabulary for the draft it is editing.
     *
     * Sending `lines` for a payment draft would otherwise be silently ignored —
     * the payment template does not read them — and the user would be told their
     * change was saved when nothing about it was. Here rather than in the form
     * request because this is the only layer that knows what the draft is.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertPayloadFitsType(Transaction $transaction, array $payload): void
    {
        $type = $transaction->type;

        if (array_key_exists('lines', $payload) && ! $type->acceptsRawLines()) {
            throw TransactionPayloadMismatchException::linesNotAccepted($type);
        }

        // acceptsPaymentSplit, not isSettlement: a bill legitimately carries a
        // part-payment, so editing one to say ₹2,000 was collected at the
        // counter is a change its template does read.
        if (array_key_exists('payments', $payload) && ! $type->acceptsPaymentSplit()) {
            throw TransactionPayloadMismatchException::paymentsNotAccepted($type);
        }

        if (array_key_exists('items', $payload) && ! $type->hasDocumentLines()) {
            throw TransactionPayloadMismatchException::itemsNotAccepted($type);
        }

        if (array_key_exists('adjustments', $payload) && $type !== TransactionType::StockAdjustment) {
            throw TransactionPayloadMismatchException::adjustmentsNotAccepted($type);
        }
    }

    public function post(int $id, ?User $actor = null): Transaction
    {
        return $this->engine->postDraft($this->find($id), $actor);
    }

    /**
     * @param  bool  $acknowledgeNegativeStock  Whoever is reversing has been told
     *                                          this will take a purchased item
     *                                          below zero and wants it anyway —
     *                                          see PostingEngine::assertReversalKeepsStockWhole().
     */
    public function reverse(
        int $id,
        ?string $date = null,
        ?string $reason = null,
        ?User $actor = null,
        bool $acknowledgeNegativeStock = false,
    ): Transaction {
        return $this->engine->reverse(
            $this->find($id),
            $date === null ? null : CarbonImmutable::parse($date),
            $reason,
            $actor,
            $acknowledgeNegativeStock,
        );
    }

    /**
     * Correct a posted **bill** — a purchase or an invoice — the Edit a posted
     * document is otherwise refused, done the only way a ledger permits.
     *
     * The engine writes the reversal and the replacement as one act; what this
     * adds is the question of whether this particular document is allowed to be
     * corrected at all, which is workflow and belongs here rather than in the
     * ledger.
     *
     * Four refusals, and each of them is a case where reverse-and-repost would
     * be the wrong repair rather than merely an unusual one:
     *
     *   * a **draft** — nothing is in the books, so `update()` edits it in place
     *     and this would post two documents to change a number nobody has seen;
     *   * an **already-reversed** document — it has been cancelled, and
     *     correcting a cancellation is writing a new bill;
     *   * anything that is **not a bill** — a credit or debit note is already
     *     the correction to something else, so correcting one is a decision
     *     about which correction stands. See
     *     {@see TransactionType::isCorrectableBill()};
     *   * a bill with **money against it**, because a payment allocated to a
     *     document that is about to be reversed would be left pointing at a
     *     cancelled bill. Unallocate or reverse the payment first, which is a
     *     decision about money and has to be made deliberately.
     *
     * An invoice passes all four and can still be refused *at the end*, by
     * {@see PostingEngine::revise()}, when the correction would re-issue its
     * goods at a cost they did not leave at. That refusal cannot be made here:
     * it depends on what both halves of the correction actually wrote.
     *
     * @param  array<string, mixed>  $payload
     */
    public function revise(
        int $id,
        array $payload,
        ?User $actor = null,
        bool $acknowledgeNegativeStock = false,
    ): Transaction {
        // The same double-submit protection `create()` gives, and it matters more
        // here: without it the second tap would find the original already
        // reversed and be refused, leaving somebody who pressed Save twice
        // looking at an error over a correction that had in fact worked.
        $clientRef = $payload['client_ref'] ?? null;

        if ($clientRef !== null && ($existing = $this->transactions->findByClientRef($clientRef)) !== null) {
            return $this->withSettlement($existing);
        }

        $original = $this->find($id);

        if ($original->isDraft()) {
            throw TransactionImmutableException::draftNotReversible($original->id);
        }

        if ($original->isReversed()) {
            throw TransactionImmutableException::reversed($original->id);
        }

        if (! $original->type->isCorrectableBill()) {
            throw TransactionPayloadMismatchException::notRevisable($original->type);
        }

        $paid = $original->settlement['paid'] ?? null;

        if ($paid !== null && (float) $paid > 0) {
            throw TransactionImmutableException::settled($original->id, (string) $paid);
        }

        // A note carries `against_transaction_id` pointing at this bill, so
        // reversing it would leave one posted against a cancelled document — the
        // same orphan a payment would be, arrived at through goods rather than
        // money. The bill has already been partly corrected; correcting it again
        // is a decision about which correction stands.
        $credited = $original->settlement['credited'] ?? null;

        if ($credited !== null && (float) $credited > 0) {
            throw TransactionImmutableException::returnedAgainst($original->id, (string) $credited);
        }

        return $this->engine->revise(
            $original,
            $this->inputFrom($payload),
            $actor,
            $acknowledgeNegativeStock,
        );
    }

    /**
     * Throw a draft away. Nothing was ever in the ledger, so there is nothing
     * to reverse and nothing to keep — and an accumulating pile of abandoned
     * drafts is its own kind of unreliable record.
     */
    public function discard(int $id): void
    {
        $transaction = $this->find($id);

        if (! $transaction->isDraft()) {
            throw TransactionImmutableException::deleting($transaction->id);
        }

        $this->transactions->delete($transaction);
    }
}
