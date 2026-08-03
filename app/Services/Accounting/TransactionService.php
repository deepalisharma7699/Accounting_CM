<?php

namespace App\Services\Accounting;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\TransactionImmutableException;
use App\Exceptions\Accounting\TransactionPayloadMismatchException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
        private readonly PostingEngine $engine,
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
        return $this->transactions->paginate($filters, $perPage);
    }

    public function find(int $id): Transaction
    {
        return $this->transactions->findWithEntries($id)
            ?? throw new ResourceNotFoundException('Transaction', $id);
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

        // Composed and posted inside one database transaction, because for a
        // type that moves stock the cost of an issue is read while composing and
        // written a moment later — see PostingEngine::postComposed(). Drafting
        // needs no such wrapper: a draft values nothing, deliberately.
        return ($payload['post'] ?? false)
            ? $this->engine->postComposed($type, $input, $actor)
            : $this->engine->draft($this->engine->compose($type, $input), $actor);
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

    public function reverse(int $id, ?string $date = null, ?string $reason = null, ?User $actor = null): Transaction
    {
        return $this->engine->reverse(
            $this->find($id),
            $date === null ? null : CarbonImmutable::parse($date),
            $reason,
            $actor,
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
