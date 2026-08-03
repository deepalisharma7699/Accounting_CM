<?php

namespace App\Services\Accounting\Posting;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A complete transaction as it would be written: a date, a type, and the lines
 * a posting template produced.
 *
 * Nothing here has touched the database. It is the unit the posting engine
 * validates and then commits — and the unit a preview screen can render, which
 * is what M15's "show me what you are about to post" depends on.
 */
final class PostingBatch
{
    /**
     * @param  array<int, PostingLine>  $lines
     * @param  int|null  $partyId  The counterparty, where the transaction has
     *                             one. Held on the batch rather than on a line
     *                             because a party belongs to the *event* — one
     *                             bill is with one customer, however many
     *                             accounts it moves.
     * @param  array<int, PaymentSplit>  $payments  How the money moved, for a
     *                             settlement. Empty for everything else. The
     *                             lines above are already derived from these, so
     *                             this is not a second source of truth — it is
     *                             what the ledger structurally cannot record: the
     *                             mode, where two modes share an account, and the
     *                             cheque number.
     * @param  array<int, StockChange>  $movements  What quantities moved — M8.
     *                             Empty for everything that does not touch stock.
     *                             The Inventory line above is built from the
     *                             total of these, exactly as a settlement's
     *                             control line is built from its split, so the
     *                             money ledger and the stock ledger cannot
     *                             disagree about what a movement was worth.
     * @param  array<string, mixed>|null  $payload  The request a template
     *                             composed from, held so a draft of a derived
     *                             type can be re-composed — and therefore
     *                             re-priced and re-taxed — at the moment it is
     *                             posted. Null for the types whose lines the user
     *                             wrote directly.
     * @param  Money|null  $documentTotal  What the *document* says it is worth,
     *                             where that is not the same as its total debits.
     *                             A sale's debits are the invoice plus the cost
     *                             of goods sold, and reporting a ₹5,000 bill as
     *                             ₹8,200 because ₹3,200 of stock left the shelf
     *                             would be wrong on every list it appears in.
     */
    public function __construct(
        public readonly TransactionType $type,
        public readonly CarbonImmutable $date,
        public readonly array $lines,
        public readonly ?string $notes = null,
        public readonly TransactionSource $source = TransactionSource::Manual,
        public readonly ?int $partyId = null,
        public readonly array $payments = [],
        public readonly array $movements = [],
        public readonly ?array $payload = null,
        public readonly ?Money $documentTotal = null,
        /**
         * @var array<int, BillLine>  The bill as it will be printed — M9. Empty
         *                            for everything that is not a document. The
         *                            ledger lines are aggregates of these (one
         *                            credit to Sales for every goods line), so
         *                            they are not recoverable from the entries at
         *                            all, and neither is the CGST/SGST/IGST split.
         */
        public readonly array $documentLines = [],
    ) {}

    /**
     * @param  array<int, PostingLine>  $lines
     * @param  array<int, PaymentSplit>  $payments
     * @param  array<int, StockChange>  $movements
     * @param  array<string, mixed>|null  $payload
     */
    public static function of(
        TransactionType $type,
        DateTimeInterface|string $date,
        array $lines,
        ?string $notes = null,
        TransactionSource $source = TransactionSource::Manual,
        ?int $partyId = null,
        array $payments = [],
        array $movements = [],
        ?array $payload = null,
        ?Money $documentTotal = null,
        array $documentLines = [],
    ): self {
        return new self(
            $type,
            CarbonImmutable::parse($date)->startOfDay(),
            array_values($lines),
            $notes,
            $source,
            $partyId,
            array_values($payments),
            array_values($movements),
            $payload,
            $documentTotal,
            array_values($documentLines),
        );
    }

    public function totalDebits(): Money
    {
        return Money::sum(array_map(fn (PostingLine $line) => $line->debitAmount(), $this->lines));
    }

    public function totalCredits(): Money
    {
        return Money::sum(array_map(fn (PostingLine $line) => $line->creditAmount(), $this->lines));
    }

    /**
     * The accounting identity, checked in whole paise. This is integer
     * arithmetic all the way down: the same check written against floats
     * rejects entries that are demonstrably correct.
     */
    public function isBalanced(): bool
    {
        return $this->totalDebits()->equals($this->totalCredits());
    }

    /**
     * The headline amount of the transaction — one side of it, not the sum of
     * both, which would double every figure in the transaction list.
     *
     * For a document whose debits include something that is not part of what it
     * is worth — a sale's cost of goods sold sits on the debit side alongside
     * the customer's invoice — the template states the figure explicitly. See
     * `$documentTotal`.
     */
    public function total(): Money
    {
        return $this->documentTotal ?? $this->totalDebits();
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }

    /**
     * The total of the settlement split. Zero when there is none.
     *
     * Equal to {@see total()} for a settlement by construction — the template
     * builds the control-account line from this very sum — which is exactly why
     * the engine can assert the two agree rather than trusting them to.
     */
    public function paymentsTotal(): Money
    {
        return PaymentSplit::total($this->payments);
    }

    public function hasPayments(): bool
    {
        return $this->payments !== [];
    }

    /**
     * What the Inventory account moves by, signed.
     *
     * Equal by construction to the inventory line the template wrote, which is
     * exactly why the engine can assert the two agree rather than trusting them
     * to — the same guarantee, and the same check, as a settlement's split.
     */
    public function stockValue(): Money
    {
        return StockChange::totalValue($this->movements);
    }

    public function hasMovements(): bool
    {
        return $this->movements !== [];
    }

    public function hasDocumentLines(): bool
    {
        return $this->documentLines !== [];
    }

    /**
     * Every line on the opposite side, dated as given: the reversing entry.
     */
    public function mirrored(DateTimeInterface|string $date, ?string $notes = null): self
    {
        return self::of(
            $this->type,
            $date,
            array_map(fn (PostingLine $line) => $line->mirrored(), $this->lines),
            $notes,
            $this->source,
            // The reversal is with the same counterparty: a credit note to the
            // customer who was over-billed, not to nobody. Dropping it here
            // would leave their outstanding overstated by the original amount
            // while the control account netted correctly.
            $this->partyId,
            // And through the same modes. A reversed receipt is the money going
            // back the way it came, so the voucher must still be able to say
            // which cheque it was.
            $this->payments,
        );
    }

    /**
     * @return array<int, array{account_id: int, debit: string, credit: string, memo: string|null}>
     */
    public function toArray(): array
    {
        return array_map(fn (PostingLine $line) => $line->toArray(), $this->lines);
    }

    /**
     * The settlement split in its storable shape, for a draft's
     * `draft_payments`, and accepted back by {@see PaymentSplit::fromInput()}
     * unchanged.
     *
     * @return array<int, array{mode: string, amount: string, reference: string|null}>
     */
    public function paymentsToArray(): array
    {
        return array_map(fn (PaymentSplit $split) => $split->toArray(), $this->payments);
    }

    /**
     * The stock movements in a renderable shape — for a preview and for the
     * posted transaction's payload, never for a draft.
     *
     * @return array<int, array<string, mixed>>
     */
    public function movementsToArray(): array
    {
        return array_map(fn (StockChange $change) => $change->toArray(), $this->movements);
    }
}
