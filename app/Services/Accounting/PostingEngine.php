<?php

namespace App\Services\Accounting;

use App\Enums\BalanceSide;
use App\Enums\SystemAccount;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\BooksClosedException;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Exceptions\Accounting\InvalidStockMovementException;
use App\Exceptions\Accounting\TransactionImmutableException;
use App\Exceptions\Accounting\UnbalancedJournalException;
use App\Models\ChartOfAccount;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Repositories\Contracts\ItemVariantRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Repositories\Contracts\PartyRepositoryInterface;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\TransactionLineRepositoryInterface;
use App\Repositories\Contracts\TransactionPaymentRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Services\Accounting\Posting\CarriesDocumentLines;
use App\Services\Accounting\Posting\MovesStock;
use App\Services\Accounting\Posting\PaymentSplit;
use App\Services\Accounting\Posting\PostingBatch;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\Posting\PostingTemplateRegistry;
use App\Services\Accounting\Posting\SettlesThroughPaymentModes;
use App\Services\Accounting\Posting\StatesItsOwnTotal;
use App\Services\Accounting\Posting\StockChange;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only thing in this application that writes to the ledger.
 *
 * Everything above it — bills, payments, imports, the capture agent — hands it
 * a {@see PostingBatch} and it does the same four things every time, in the
 * same order:
 *
 *   1. Build the lines from the transaction type's posting template.
 *   2. Validate them: two lines at least, one side each, a real and unarchived
 *      account, debits equal to credits, and a date the books are open on.
 *   3. Write the transaction and its entries in **one** database transaction.
 *   4. Refuse everything else.
 *
 * The reason this is one class rather than a method on each caller is that
 * every module below M4 inherits its correctness. A second write path would
 * mean a second place for the balance check to be forgotten, and an unbalanced
 * ledger is invisible for months and then unrecoverable.
 *
 * Note that it never *repairs* anything. An unbalanced batch is refused whole;
 * there is no rounding line, no plug and no partial write.
 */
class PostingEngine
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly JournalEntryRepositoryInterface $entries,
        private readonly ChartOfAccountRepositoryInterface $accounts,
        private readonly TenantRepositoryInterface $tenants,
        private readonly PartyRepositoryInterface $parties,
        private readonly TransactionPaymentRepositoryInterface $payments,
        private readonly StockMovementRepositoryInterface $stock,
        private readonly TransactionLineRepositoryInterface $documentLines,
        private readonly ItemVariantRepositoryInterface $variants,
        private readonly PostingTemplateRegistry $templates,
        private readonly TenantContext $context,
    ) {}

    /* ---------------------------------------------------------------------
     | Building
     |-------------------------------------------------------------------- */

    /**
     * Turn a validated payload into the batch its type implies, without
     * writing anything.
     *
     * Public because a preview is worth having on its own: M15 shows the user
     * exactly what is about to be posted before they authorise it, and it must
     * be the same lines the engine would write, not a second rendering of them.
     *
     * @param  array<string, mixed>  $input
     */
    public function compose(TransactionType $type, array $input): PostingBatch
    {
        $template = $this->templates->for($type);

        return PostingBatch::of(
            type: $type,
            date: $input['date'],
            lines: $template->build($input),
            notes: $input['notes'] ?? null,
            source: $input['source'] ?? TransactionSource::Manual,
            partyId: $input['party_id'] ?? null,
            // Read from the same template, from the same payload, in the same
            // call that produced the lines — so what is recorded as "how the
            // money moved" is what the ledger lines actually say, not a second
            // interpretation of the input that could differ from it.
            payments: $template instanceof SettlesThroughPaymentModes
                ? $template->splitsFrom($input)
                : [],
            // And the same for quantities. The template memoises, so the issue
            // valuation `build()` put on the Inventory line is the identical
            // object recorded as the movement — not a second valuation that a
            // concurrent sale could have moved between the two calls.
            movements: $template instanceof MovesStock
                ? $template->stockChangesFrom($input)
                : [],
            // The invoice itself, from the same template and the same call —
            // so what the customer is handed and what the books record are the
            // same computation, not two readings of one payload.
            documentLines: $template instanceof CarriesDocumentLines
                ? $template->documentLinesFrom($input)
                : [],
            // Held so a draft of a derived type can be re-composed at the moment
            // it is posted, rather than posting a fortnight-old valuation.
            payload: $type->composesFromPayload() ? $this->payloadOf($input) : null,
            documentTotal: $template instanceof StatesItsOwnTotal
                ? $template->documentTotal($input)
                : null,
        );
    }

    /**
     * The part of a compose payload that is worth keeping on a draft.
     *
     * The request, not the result: items, quantities, prices and the expense
     * account, with the date and party stripped out because those live in
     * columns of their own and a second copy is a second thing to keep in step.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function payloadOf(array $input): array
    {
        return array_diff_key($input, array_flip(['date', 'notes', 'party_id', 'source', 'payments']));
    }

    /* ---------------------------------------------------------------------
     | Posting
     |-------------------------------------------------------------------- */

    /**
     * Compose a payload and post it, atomically.
     *
     * The entry point anything that moves stock must use, and the reason it
     * exists: the cost of an issue is read from the stock ledger while it is
     * being composed, and written a moment later. Two simultaneous sales of the
     * last motor would otherwise both read the pre-sale average, both value
     * their cost of goods sold against it, and leave the Inventory account
     * describing stock that was sold twice. Wrapping the pair means the variant
     * lock the valuation takes is still held when the movement lands.
     *
     * A preview — {@see compose()} on its own — deliberately does not do this.
     * It is advisory by nature and holding write locks open across a user
     * looking at a screen would be far worse than a preview that a concurrent
     * sale moved by a rupee.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidJournalException|UnbalancedJournalException|BooksClosedException
     */
    public function postComposed(TransactionType $type, array $input, ?User $actor = null): Transaction
    {
        return DB::transaction(fn () => $this->post($this->compose($type, $input), $actor));
    }

    /**
     * Validate a batch and commit it to the ledger.
     *
     * @throws InvalidJournalException|UnbalancedJournalException|BooksClosedException
     */
    public function post(PostingBatch $batch, ?User $actor = null): Transaction
    {
        $this->assertPostable($batch);

        // One transaction around the header and every line. A crash between
        // them would otherwise leave a transaction with half its entries —
        // which is precisely an unbalanced ledger, arrived at by accident.
        //
        // The settlement rows and the stock movements join the same wrapper
        // rather than getting write paths of their own: everything a business
        // event implies commits together or not at all. A purchase that recorded
        // three motors on the shelf without the payable that bought them is not
        // a smaller error than an unbalanced journal — it is the same error, in
        // a table where nothing checks the totals.
        $transaction = DB::transaction(function () use ($batch, $actor) {
            $transaction = $this->transactions->create([
                'type' => $batch->type,
                'status' => TransactionStatus::Posted,
                'source' => $batch->source,
                'party_id' => $batch->partyId,
                'date' => $batch->date,
                'total' => $batch->total()->amount(),
                'notes' => $batch->notes,
                'draft_lines' => null,
                'draft_payments' => null,
                'draft_payload' => null,
                'created_by' => $actor?->id,
                'posted_at' => CarbonImmutable::now(),
            ]);

            $this->entries->writeFor($transaction, $batch->lines);
            $this->writePayments($transaction, $batch);
            $this->writeStock($transaction, $batch, $this->writeDocumentLines($transaction, $batch));

            return $transaction;
        });

        Log::info('ledger.posted', [
            'transaction_id' => $transaction->id,
            'tenant_id' => $transaction->tenant_id,
            'type' => $batch->type->value,
            'lines' => $batch->lineCount(),
            'movements' => count($batch->movements),
            'total' => $batch->total()->amount(),
        ]);

        return $transaction->load(['entries.account', 'payments', 'lines.stockMovement', 'stockMovements', 'creator']);
    }

    /**
     * Save a batch without posting it. Nothing reaches the ledger: the lines
     * are held on the transaction row until somebody authorises them.
     *
     * A draft is allowed to be unbalanced — it is work in progress, and
     * refusing to save a half-written voucher would only push people into
     * posting before they are ready. The balance is enforced at {@see
     * postDraft()}, which is the moment it starts to matter.
     */
    public function draft(PostingBatch $batch, ?User $actor = null): Transaction
    {
        $this->assertLinesUsable($batch);
        $this->assertPartyUsable($batch);
        $this->assertBooksOpenOn($batch->date);

        return $this->transactions->create([
            'type' => $batch->type,
            'status' => TransactionStatus::Draft,
            'source' => $batch->source,
            'party_id' => $batch->partyId,
            'date' => $batch->date,
            'total' => $batch->total()->amount(),
            'notes' => $batch->notes,
            'draft_lines' => $batch->toArray(),
            // Held on the row rather than in transaction_payments, exactly as
            // the intended lines are held out of the ledger: nothing reading
            // settlements has to remember to filter unauthorised work out.
            'draft_payments' => $batch->hasPayments() ? $batch->paymentsToArray() : null,
            // And the request itself, for the types whose lines are derived. The
            // stock movements are *not* stored: they are re-valued when the
            // draft is finally posted, because the cost of goods sold on a
            // fortnight-old draft is not the cost of goods sold today.
            'draft_payload' => $batch->payload,
            'created_by' => $actor?->id,
            'posted_at' => null,
        ]);
    }

    /**
     * Replace a draft's contents. Refused for anything already in the books.
     */
    public function updateDraft(Transaction $draft, PostingBatch $batch): Transaction
    {
        if (! $draft->isDraft()) {
            throw TransactionImmutableException::posted($draft->id);
        }

        $this->assertLinesUsable($batch);
        $this->assertPartyUsable($batch);
        $this->assertBooksOpenOn($batch->date);

        return $this->transactions->update($draft, [
            'party_id' => $batch->partyId,
            'date' => $batch->date,
            'total' => $batch->total()->amount(),
            'notes' => $batch->notes,
            'draft_lines' => $batch->toArray(),
            'draft_payments' => $batch->hasPayments() ? $batch->paymentsToArray() : null,
            'draft_payload' => $batch->payload,
        ]);
    }

    /**
     * Authorise a draft: validate it properly this time, write its entries and
     * move it into the books.
     *
     * The draft's own row becomes the posted transaction rather than a copy of
     * it, so a reference held anywhere else — a capture session, a worklist —
     * still points at the right thing afterwards.
     */
    public function postDraft(Transaction $draft, ?User $actor = null): Transaction
    {
        if (! $draft->isDraft()) {
            throw TransactionImmutableException::notADraft($draft->id);
        }

        // Rebuilt *inside* the transaction, not before it. For a derived type
        // that is where the stock is valued, and the variant lock it takes has
        // to still be held when the movement is written a few lines later.
        $posted = DB::transaction(function () use ($draft, $actor) {
            $batch = $this->batchFromDraft($draft);

            $this->assertPostable($batch);

            $this->entries->writeFor($draft, $batch->lines);
            $this->writePayments($draft, $batch);
            $this->writeStock($draft, $batch, $this->writeDocumentLines($draft, $batch));

            // draft_lines is cleared in the same statement that posts, so there
            // is never a moment when the intended lines and the written ones
            // both exist and could disagree. Same for the settlement split and
            // the composed-from payload.
            return $this->transactions->update($draft, [
                'status' => TransactionStatus::Posted,
                'total' => $batch->total()->amount(),
                'draft_lines' => null,
                'draft_payments' => null,
                'draft_payload' => null,
                'posted_at' => CarbonImmutable::now(),
                'created_by' => $draft->created_by ?? $actor?->id,
            ]);
        });

        Log::info('ledger.draft_posted', [
            'transaction_id' => $posted->id,
            'tenant_id' => $posted->tenant_id,
            'total' => $posted->total,
        ]);

        return $posted->load(['entries.account', 'payments', 'lines.stockMovement', 'stockMovements', 'creator']);
    }

    /* ---------------------------------------------------------------------
     | Reversing
     |-------------------------------------------------------------------- */

    /**
     * Cancel a posted transaction with a mirrored one.
     *
     * This is how accounting corrects itself: by addition. The original keeps
     * its entries, the reversal contributes the opposite ones, and the net
     * effect on every account is nil — while both remain visible to anyone
     * asking what happened. Editing the original instead would silently change
     * every report already run from it.
     *
     * The reversal is dated today by default rather than on the original's
     * date, so it lands in the period it was actually decided in. Passing a
     * date allows a same-period correction, which is what an accountant closing
     * a month wants.
     */
    public function reverse(
        Transaction $original,
        ?DateTimeInterface $date = null,
        ?string $reason = null,
        ?User $actor = null,
    ): Transaction {
        if ($original->isDraft()) {
            throw TransactionImmutableException::draftNotReversible($original->id);
        }

        if ($original->isReversed()) {
            throw TransactionImmutableException::reversed($original->id);
        }

        $original->loadMissing(['entries', 'payments', 'stockMovements']);

        // Every line on the opposite side. Nothing is recalculated: whatever
        // the original put into the books, this takes back out, so the pair
        // nets to zero in every account even if the rules that produced the
        // original have since changed.
        $lines = $original->entries
            ->map(fn ($entry) => PostingLine::on(
                $entry->side()->opposite(),
                $entry->account_id,
                $entry->amount(),
                $entry->memo,
            ))
            ->all();

        $batch = PostingBatch::of(
            type: $original->type,
            date: $date ?? CarbonImmutable::now(),
            lines: $lines,
            notes: $reason ?? "Reversal of transaction #{$original->id}",
            source: $original->source,
            // Carried over, so the correction lands on the same party ledger as
            // the mistake. A reversal that dropped the party would leave their
            // outstanding overstated for ever, while the control account it
            // rolls up into netted to nothing — the two disagreeing about the
            // same money, which is the failure a derived balance exists to
            // make impossible.
            partyId: $original->party_id,
            // Copied, not re-derived: a reversed receipt is the same money going
            // back the way it came, and a voucher that could not say which cheque
            // was returned would be useless at the only moment anyone reads it.
            payments: $original->payments
                ->map(fn ($payment) => PaymentSplit::of(
                    $payment->mode,
                    $payment->amountMoney(),
                    $payment->reference,
                ))
                ->all(),
            // Every quantity back the way it came, at the value it left at.
            // Re-valuing here would be wrong twice over: the average has moved
            // since, and a reversal is supposed to net to nothing — a return
            // valued at today's average would leave the Inventory account
            // holding the difference for ever, against stock that is there.
            movements: $original->stockMovements
                ->map(fn ($movement) => StockChange::reversing($movement))
                ->all(),
            documentTotal: $original->totalMoney(),
        );

        // Archived parties are allowed here, and only here. Archiving says "no
        // new business with them"; it cannot mean "the last entry we made
        // against them can never be corrected", which would leave a known error
        // permanently in the books.
        $this->assertPostable($batch, allowArchivedParty: true);

        $reversal = DB::transaction(function () use ($batch, $original, $actor) {
            $reversal = $this->transactions->create([
                'type' => $batch->type,
                'status' => TransactionStatus::Posted,
                'source' => $batch->source,
                'party_id' => $batch->partyId,
                'date' => $batch->date,
                'total' => $batch->total()->amount(),
                'notes' => $batch->notes,
                'created_by' => $actor?->id,
                'posted_at' => CarbonImmutable::now(),
                'reverses_id' => $original->id,
            ]);

            $this->entries->writeFor($reversal, $batch->lines);
            $this->writePayments($reversal, $batch);
            $this->writeStock($reversal, $batch, $this->writeDocumentLines($reversal, $batch));

            // The only change a posted transaction ever undergoes, and the only
            // one Transaction's immutability guard permits.
            $this->transactions->update($original, ['status' => TransactionStatus::Reversed]);

            return $reversal;
        });

        Log::info('ledger.reversed', [
            'transaction_id' => $original->id,
            'reversal_id' => $reversal->id,
            'tenant_id' => $reversal->tenant_id,
        ]);

        return $reversal->load(['entries.account', 'payments', 'lines.stockMovement', 'stockMovements', 'creator']);
    }

    /**
     * Record how the money moved, where the transaction moved any.
     *
     * Always called from inside the caller's `DB::transaction`, never on its own:
     * a settlement row without the entries it describes claims money moved when
     * nothing did.
     */
    private function writePayments(Transaction $transaction, PostingBatch $batch): void
    {
        if (! $batch->hasPayments()) {
            return;
        }

        $this->payments->writeFor($transaction, $batch->payments);
    }

    /**
     * Record what quantities moved, where the transaction moved any.
     *
     * Always called from inside the caller's `DB::transaction`, never on its
     * own: a stock movement without the entries that value it is inventory
     * appearing from nowhere, and the whole point of writing them together is
     * that the Inventory account and the shelf can never drift apart.
     *
     * The document lines are written *first* where there are any, so each
     * movement can be pointed at the bill line that produced it — which is what
     * makes a line's margin a join rather than a second copy of its cost.
     */
    private function writeStock(Transaction $transaction, PostingBatch $batch, Collection $lines): void
    {
        if (! $batch->hasMovements()) {
            return;
        }

        $this->stock->writeFor($transaction, $batch->movements, $lines);
    }

    /**
     * Record what was billed, where the transaction is a document.
     *
     * @return Collection<int, \App\Models\TransactionLine>  Keyed by line number.
     */
    private function writeDocumentLines(Transaction $transaction, PostingBatch $batch): Collection
    {
        if (! $batch->hasDocumentLines()) {
            return new Collection;
        }

        return $this->documentLines->writeFor($transaction, $batch->documentLines);
    }

    /* ---------------------------------------------------------------------
     | The invariants
     |-------------------------------------------------------------------- */

    /**
     * Everything that must be true before a line reaches the ledger.
     *
     * Ordered from the cheapest and most specific check to the most general, so
     * the message a user sees names the actual problem: "line 3 has no amount"
     * rather than "debits and credits do not balance".
     *
     * @throws InvalidJournalException|UnbalancedJournalException|BooksClosedException
     */
    private function assertPostable(PostingBatch $batch, bool $allowArchivedParty = false): void
    {
        // Double entry means at least two accounts. A single line cannot
        // balance, so this is caught before the totals are even compared.
        if ($batch->lineCount() < 2) {
            throw InvalidJournalException::tooFewLines($batch->lineCount());
        }

        $this->assertLinesUsable($batch);
        $this->assertPartyUsable($batch, $allowArchivedParty);
        $this->assertSettlementUsable($batch);
        $this->assertStockUsable($batch, $allowArchivedParty);
        $this->assertBooksOpenOn($batch->date);

        if (! $batch->isBalanced()) {
            throw UnbalancedJournalException::between($batch->totalDebits(), $batch->totalCredits());
        }
    }

    /**
     * What must be true of a settlement specifically: money moved through at
     * least one named mode, and the split agrees with the entries.
     *
     * Both hold by construction when the batch came from a settlement template —
     * it builds the control-account line *from* the split's total. They are
     * asserted anyway because `PostingBatch` is public: M11's importer and M15's
     * capture agent can compose one by hand, and a ₹5,000 receipt recorded
     * against ₹4,000 of entries would leave the two halves of one document
     * contradicting each other, permanently and silently.
     *
     * @throws InvalidJournalException
     */
    private function assertSettlementUsable(PostingBatch $batch): void
    {
        if ($batch->type->isSettlement() && ! $batch->hasPayments()) {
            throw InvalidJournalException::paymentSplitRequired(strtolower($batch->type->label()));
        }

        if (! $batch->hasPayments()) {
            return;
        }

        if (! $batch->type->acceptsPaymentSplit()) {
            throw InvalidJournalException::paymentSplitNotAccepted(strtolower($batch->type->label()));
        }

        $split = $batch->paymentsTotal();

        // A settlement *is* its split, so the two must be equal: compared
        // against one side, not the sum of both, because the settlement lines
        // are one side of the voucher and the control-account line is the other.
        if ($batch->type->isSettlement() && ! $split->equals($batch->total())) {
            throw InvalidJournalException::paymentSplitMismatch($split->amount(), $batch->total()->amount());
        }

        // A bill merely *accepts* one, so the rule is weaker and different: pay
        // some, all or none of the invoice, but not more of it than exists. An
        // over-payment against a bill is not a credit balance waiting to be
        // used — it is a typo, and the money it claims to have collected would
        // sit in Receivables as a negative nobody asked for.
        if (! $batch->type->isSettlement() && $split->compareTo($batch->total()) > 0) {
            throw InvalidJournalException::paymentSplitExceedsDocument($split->amount(), $batch->total()->amount());
        }
    }

    /**
     * What must be true of the quantities: real variants of this workshop, items
     * that are actually inventoried, and — the invariant this module exists for
     * — an Inventory line that agrees with them to the paise.
     *
     * That last check is what makes the roadmap's "stock value in the Inventory
     * ledger equals Σ(qty × cost)" true by construction rather than by hoping.
     * The templates build the Inventory line *from* the movements, so it holds
     * by construction there too; it is asserted anyway because `PostingBatch` is
     * public, and M11's importer and M15's capture agent can compose one by hand.
     *
     * @throws InvalidStockMovementException|InvalidJournalException
     */
    private function assertStockUsable(PostingBatch $batch, bool $allowArchived = false): void
    {
        if (! $batch->hasMovements()) {
            // A type that may move stock and did not is perfectly ordinary — a
            // bill for labour alone. What is not ordinary is a type that may not
            // move stock arriving with movements, which is caught below.
            if (! $batch->type->movesStock()) {
                return;
            }
        }

        if ($batch->hasMovements() && ! $batch->type->movesStock()) {
            throw InvalidJournalException::stockNotAccepted(strtolower($batch->type->label()));
        }

        foreach ($batch->movements as $change) {
            $this->assertChangeUsable($change, $allowArchived);
        }

        $stockValue = $batch->stockValue();
        $inventoryLine = $this->inventoryMovementIn($batch);

        if (! $inventoryLine->equals($stockValue)) {
            throw InvalidJournalException::stockValueMismatch(
                $stockValue->amount(),
                $inventoryLine->amount(),
            );
        }
    }

    /**
     * One movement's variant, checked the same way a line's account is: it must
     * exist **in this workshop**, which the tenant scope guarantees since a
     * foreign id simply does not resolve, and it must still be in use.
     *
     * @throws InvalidStockMovementException
     */
    private function assertChangeUsable(StockChange $change, bool $allowArchived): void
    {
        $variant = $this->variants->findWithItem($change->variantId)
            ?? throw InvalidStockMovementException::unknownVariant($change->variantId);

        // Allowed on a reversal, and only there — for exactly the reason an
        // archived party is: archiving means "no new business", and it cannot be
        // permitted to strand a known error permanently in the books.
        if (! $allowArchived && ! $variant->is_active) {
            throw InvalidStockMovementException::archivedVariant($variant->id, $variant->displayLabel());
        }

        if ($variant->item === null) {
            throw InvalidStockMovementException::unknownItem($variant->id);
        }

        // Skipped on a reversal too: an item the workshop has since stopped
        // inventorying still has to be able to give back the stock it took.
        if (! $allowArchived && ! $variant->item->tracksStock()) {
            throw InvalidStockMovementException::notStocked(
                $variant->displayLabel(),
                $variant->item->type->label(),
            );
        }

        if (! $change->quantity->fitsUnit($variant->item->base_uom)) {
            throw InvalidStockMovementException::fractionalUnit(
                $variant->displayLabel(),
                $change->quantity->trimmed(),
                $variant->item->base_uom->label(),
            );
        }
    }

    /**
     * The signed amount the batch moves the Inventory account by: debits minus
     * credits on that one account.
     */
    private function inventoryMovementIn(PostingBatch $batch): Money
    {
        $inventory = $this->accounts->findBySystemKey(SystemAccount::Inventory);

        if ($inventory === null) {
            return Money::zero();
        }

        $total = Money::zero();

        foreach ($batch->lines as $line) {
            if ($line->accountId !== $inventory->id) {
                continue;
            }

            $total = $line->side === BalanceSide::Debit
                ? $total->plus($line->amount)
                : $total->minus($line->amount);
        }

        return $total;
    }

    /**
     * Per-line checks that apply to a draft as much as to a posting: a real,
     * postable account and a positive amount.
     *
     * @throws InvalidJournalException
     */
    private function assertLinesUsable(PostingBatch $batch): void
    {
        foreach ($batch->lines as $index => $line) {
            $lineNumber = $index + 1;

            // Zero moves nothing, and a negative amount is the other side
            // written confusingly — the side already carries the direction, so
            // every stored amount is positive.
            if (! $line->amount->isPositive()) {
                throw InvalidJournalException::nonPositiveAmount($lineNumber);
            }

            $account = $this->resolveAccount($line->accountId, $lineNumber);

            // Archived means the workshop has decided it no longer uses the
            // account. Its history stays readable; new entries do not go there.
            if (! $account->is_active) {
                throw InvalidJournalException::archivedAccount($lineNumber, $account->name);
            }
        }
    }

    /**
     * The account must exist **in this workshop's chart** — which the tenant
     * scope guarantees, since a foreign id simply does not resolve.
     */
    private function resolveAccount(int $accountId, int $lineNumber): ChartOfAccount
    {
        return $this->accounts->findById($accountId)
            ?? throw InvalidJournalException::unknownAccount($lineNumber, $accountId);
    }

    /**
     * The counterparty, where the batch names one, must be a real and
     * untargeted-for-archival party of *this* workshop.
     *
     * Checked here rather than in a form request because every future entry
     * point — M6's payments, M9's bills, M11's importer, M15's capture agent —
     * passes through this engine, and a party id validated in one controller
     * says nothing about the other five. It is the same reasoning that puts the
     * account and balance checks here.
     *
     * The tenant scope does the isolation half on its own: another workshop's
     * party id does not resolve, so a transaction cannot be attributed across a
     * boundary even if an id is guessed.
     *
     * @throws InvalidJournalException
     */
    private function assertPartyUsable(PostingBatch $batch, bool $allowArchived = false): void
    {
        if ($batch->partyId === null) {
            // A settlement without a counterparty puts money into a control
            // account that no statement could ever account for: the cash is gone
            // and nothing records whose debt it discharged.
            if ($batch->type->requiresParty()) {
                throw InvalidJournalException::partyRequired(strtolower($batch->type->label()));
            }

            return;
        }

        $party = $this->parties->findById($batch->partyId)
            ?? throw InvalidJournalException::unknownParty($batch->partyId);

        if (! $allowArchived && ! $party->is_active) {
            throw InvalidJournalException::archivedParty($party->id, $party->name);
        }

        $this->assertPartyRoleMatches($batch, $party, $allowArchived);
    }

    /**
     * The counterparty must hold the role the transaction's control account
     * asserts they do: a payment names a vendor, a receipt names a customer.
     *
     * Debiting Sundry Creditors *is* the claim "we owed this business money", so
     * recording a payment against a customer-only party would invent a supplier
     * relationship — and leave a position on a record nobody would think to look
     * at. It is the same reasoning that stopped M5 from pushing an overpayment
     * onto the payable side.
     *
     * This is the one place a role gates a write. Roles still never filter a
     * *read* — a statement covers both control accounts whatever roles are
     * ticked — because the two are different questions, and conflating them is
     * how an edit to a label comes to hide money.
     *
     * Skipped on a reversal (`$allowArchived`), for the same reason an archived
     * party is allowed there: a role removed after the fact cannot be permitted
     * to strand a known error in the books.
     *
     * @throws InvalidJournalException
     */
    private function assertPartyRoleMatches(PostingBatch $batch, Party $party, bool $allowArchived): void
    {
        $required = $batch->type->requiredPartyRole();

        if ($required === null || $allowArchived || $party->hasRole($required)) {
            return;
        }

        throw InvalidJournalException::partyRoleMismatch(
            $party->id,
            $party->name,
            $required->label(),
            $batch->type->label(),
        );
    }

    /**
     * The go-live rule from M2.2, enforced. Everything before `books_start_date`
     * belongs to whatever the workshop kept previously and arrives once, as
     * opening balances.
     *
     * @throws BooksClosedException
     */
    private function assertBooksOpenOn(DateTimeInterface $date): void
    {
        $tenantId = $this->context->requireTenant('posting to the ledger');
        $tenant = $this->tenants->findById($tenantId);

        if ($tenant !== null && ! $tenant->acceptsPostingOn($date)) {
            throw BooksClosedException::before($tenant, $date);
        }
    }

    /* ---------------------------------------------------------------------
     | Drafts
     |-------------------------------------------------------------------- */

    /**
     * Rebuild a batch from a stored draft. The lines go back through
     * {@see PostingLine::fromInput()}, so a draft written months ago is
     * validated by today's rules rather than trusted because it was saved once.
     *
     * For a type whose lines were *derived*, the same principle goes further:
     * the stored request is put back through the template, so a bill parked for
     * a fortnight is re-priced, re-taxed and re-costed at the moment it is
     * authorised. A draft that posted its original cost of goods sold would be
     * reporting a margin against a price the workshop had stopped paying — and
     * would take stock out of the ledger at a value the shelf no longer held.
     */
    public function batchFromDraft(Transaction $draft): PostingBatch
    {
        if ($draft->type->composesFromPayload()) {
            return $this->compose($draft->type, array_merge($draft->draft_payload ?? [], [
                'date' => $draft->date,
                'notes' => $draft->notes,
                'party_id' => $draft->party_id,
                'source' => $draft->source,
                'payments' => $draft->draft_payments ?? [],
            ]));
        }

        $lines = [];

        foreach (array_values($draft->draft_lines ?? []) as $index => $line) {
            $lines[] = PostingLine::fromInput($line, $index + 1);
        }

        $payments = [];

        // Back through fromInput() as well, so a draft cheque saved before the
        // reference rule existed is judged by today's rules rather than trusted
        // because it was saved once.
        foreach (array_values($draft->draft_payments ?? []) as $index => $split) {
            $payments[] = PaymentSplit::fromInput($split, $index + 1);
        }

        return PostingBatch::of(
            type: $draft->type,
            date: $draft->date,
            lines: $lines,
            notes: $draft->notes,
            source: $draft->source,
            partyId: $draft->party_id,
            payments: $payments,
        );
    }
}
