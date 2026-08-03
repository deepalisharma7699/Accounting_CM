<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\SystemAccount;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Exceptions\Accounting\InvalidStockMovementException;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Repositories\Contracts\ItemVariantRepositoryInterface;
use App\Repositories\Contracts\PartyRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Accounting\ChartOfAccountService;
use App\Services\Accounting\Posting\BillLine;
use App\Services\Accounting\Posting\CarriesDocumentLines;
use App\Services\Accounting\Posting\MovesStock;
use App\Services\Accounting\Posting\PaymentSplit;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\Posting\PostingTemplate;
use App\Services\Accounting\Posting\SettlesThroughPaymentModes;
use App\Services\Accounting\Posting\StatesItsOwnTotal;
use App\Services\Accounting\Posting\StockChange;
use App\Services\Accounting\Tax\GstBreakdown;
use App\Services\Accounting\Tax\PlaceOfSupply;
use App\Services\Inventory\StockLedgerService;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\Tenancy\TenantContext;

/**
 * The shared shape of templates A, B and C: a document with items on it.
 *
 * ## Why a sale and a rewinding job are one template, not two
 *
 * The roadmap calls them A and B. They are the same document. A motor sold over
 * the counter and a rewind billing labour, copper and a bearing differ only in
 * the *mix* of lines — and everything hard about a bill is identical in both:
 * the tax arithmetic, the intra/inter-state split, the cost of what left the
 * shelf, the part-payment at the counter. Writing that twice would mean two
 * places for the same rounding decision to drift apart, and one of them would be
 * the one on the government return.
 *
 * So the mix is data. A line whose item is a service credits Service Income; a
 * line whose item is goods credits Sales. Nothing else changes.
 *
 * ## What a bill actually does
 *
 * ```
 * Sale        Dr Sundry Debtors   invoice total, tax included
 *             Cr Sales            goods, taxable value
 *             Cr Service Income   labour, taxable value
 *             Cr GST Output       the tax
 *             Dr COGS  / Cr Inventory   per stock line, at weighted average cost
 *             Dr Cash-Bank-UPI / Cr Sundry Debtors   whatever was collected now
 *
 * Purchase    Dr Inventory        per stock line, taxable value
 *             Dr COGS             anything bought and not stocked
 *             Dr GST Input        the tax, claimable
 *             Cr Sundry Creditors invoice total, tax included
 *             Dr Sundry Creditors / Cr Cash-Bank-UPI   whatever was paid now
 * ```
 *
 * ## Why the payment is part of the bill
 *
 * Because at a counter it is one event. "₹2,000 cash of a ₹5,000 invoice" as two
 * documents means the second can be forgotten, and a receivable that never
 * existed sits on a statement. The split is *optional* — a sale on thirty-day
 * terms carries none — and it may not exceed the invoice, because collecting more
 * than the document is worth is a typo rather than an advance. An advance is a
 * receipt with no invoice behind it, which M6 already handles.
 */
abstract class BillTemplate implements CarriesDocumentLines, MovesStock, PostingTemplate, SettlesThroughPaymentModes, StatesItsOwnTotal
{
    /**
     * Memoised, because {@see build()} and the engine's own reads of
     * {@see documentLinesFrom()} and {@see stockChangesFrom()} all happen inside
     * one `compose()` on one instance. Re-pricing between them would be at best
     * three queries and at worst — under a concurrent sale — three answers.
     *
     * @var array<int, BillLine>|null
     */
    private ?array $documentLines = null;

    /**
     * @var array<int, StockChange>|null
     */
    private ?array $stockChanges = null;

    public function __construct(
        protected readonly ChartOfAccountService $accounts,
        protected readonly StockLedgerService $stock,
        protected readonly ItemRepositoryInterface $items,
        protected readonly ItemVariantRepositoryInterface $variants,
        protected readonly PartyRepositoryInterface $parties,
        protected readonly TenantRepositoryInterface $tenants,
        protected readonly TenantContext $context,
    ) {}

    /* ---------------------------------------------------------------------
     | What the two directions disagree about
     |-------------------------------------------------------------------- */

    /** Sundry Debtors for a sale, Sundry Creditors for a purchase. */
    abstract protected function controlAccount(): SystemAccount;

    /**
     * The side the counterparty's line sits on: debit for a sale — the customer
     * owes us — and credit for a purchase.
     */
    abstract protected function controlIsDebit(): bool;

    /** GST Output on a sale, GST Input on a purchase. */
    abstract protected function taxAccount(): SystemAccount;

    /**
     * The accounts the body of the bill posts to. This is the whole of the
     * difference between selling and buying.
     *
     * The stock changes are handed in already valued rather than being looked up
     * again here, and that is the point of the memo above: valuing an issue twice
     * is not merely a wasted query — under a concurrent sale it is two answers,
     * and the Inventory line would then disagree with the movement it is supposed
     * to describe.
     *
     * @param  array<int, BillLine>  $lines
     * @param  array<int, StockChange>  $changes  Keyed by line number.
     * @return array<int, PostingLine>
     */
    abstract protected function bodyLines(array $lines, array $changes): array;

    /* ---------------------------------------------------------------------
     | Building
     |-------------------------------------------------------------------- */

    /**
     * @param  array{items?: array<int, array<string, mixed>>, payments?: array<int, array<string, mixed>>, party_id?: int|null}  $input
     * @return array<int, PostingLine>
     *
     * @throws InvalidJournalException|InvalidStockMovementException
     */
    public function build(array $input): array
    {
        $documentLines = $this->documentLinesFrom($input);
        $changes = $this->changesByLine($input);
        $total = BillLine::invoiceTotal($documentLines);
        $totals = GstBreakdown::totals(BillLine::breakdownsOf($documentLines));

        $control = $this->accounts->system($this->controlAccount())->id;

        // One control-account line for the whole invoice, tax included — a party
        // owes a single amount, and splitting their side of it across a row per
        // item would make a statement unreadable for no gain. The same reasoning
        // M6 applied to a settlement's modes.
        $lines = [
            PostingLine::on(
                $this->controlIsDebit() ? \App\Enums\BalanceSide::Debit : \App\Enums\BalanceSide::Credit,
                $control,
                $total,
                $input['notes'] ?? null,
            ),
        ];

        $lines = array_merge($lines, $this->bodyLines($documentLines, $changes));

        if (! $totals['tax']->isZero()) {
            $lines[] = PostingLine::on(
                $this->controlIsDebit() ? \App\Enums\BalanceSide::Credit : \App\Enums\BalanceSide::Debit,
                $this->accounts->system($this->taxAccount())->id,
                $totals['tax'],
                $this->taxMemo($documentLines),
            );
        }

        return array_merge($lines, $this->settlementLines($input, $control));
    }

    /**
     * Whatever was collected or paid at the counter, as its own pair of lines
     * against the control account.
     *
     * Never merged into the control line. "₹5,000 invoiced, ₹2,000 collected" is
     * two facts, and a voucher showing only the ₹3,000 net cannot answer either
     * question — how much was billed, or what was actually taken.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, PostingLine>
     */
    private function settlementLines(array $input, int $control): array
    {
        $splits = $this->splitsFrom($input);

        if ($splits === []) {
            return [];
        }

        $lines = [];

        foreach ($splits as $split) {
            // A sale debits the customer and then, as they pay, debits cash and
            // credits the customer back down. A purchase is the mirror.
            $lines[] = PostingLine::on(
                $this->controlIsDebit() ? \App\Enums\BalanceSide::Debit : \App\Enums\BalanceSide::Credit,
                $this->accounts->system($split->account())->id,
                $split->amount,
                $split->memo(),
            );

            $lines[] = PostingLine::on(
                $this->controlIsDebit() ? \App\Enums\BalanceSide::Credit : \App\Enums\BalanceSide::Debit,
                $control,
                $split->amount,
                $split->memo(),
            );
        }

        return $lines;
    }

    /**
     * The splits a bill carries. **Optional**, unlike a settlement's: a sale on
     * credit is a complete document with nothing paid against it.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, PaymentSplit>
     */
    public function splitsFrom(array $input): array
    {
        $rows = array_values((array) ($input['payments'] ?? []));

        $splits = [];

        foreach ($rows as $index => $row) {
            $splits[] = $row instanceof PaymentSplit
                ? $row
                : PaymentSplit::fromInput((array) $row, $index + 1);
        }

        return $splits;
    }

    public function documentTotal(array $input): Money
    {
        return BillLine::invoiceTotal($this->documentLinesFrom($input));
    }

    /* ---------------------------------------------------------------------
     | The document
     |-------------------------------------------------------------------- */

    /**
     * @param  array{items?: array<int, array<string, mixed>>}  $input
     * @return array<int, BillLine>
     *
     * @throws InvalidJournalException
     */
    public function documentLinesFrom(array $input): array
    {
        if ($this->documentLines !== null) {
            return $this->documentLines;
        }

        $rows = array_values((array) ($input['items'] ?? []));

        if ($rows === []) {
            throw InvalidJournalException::billHasNoLines(strtolower($this->type()->label()));
        }

        $place = $this->placeOfSupplyFor($input['party_id'] ?? null);

        $lines = [];

        foreach ($rows as $index => $row) {
            if ($row instanceof BillLine) {
                $lines[] = $row;

                continue;
            }

            $lines[] = $this->lineFrom((array) $row, $index + 1, $place);
        }

        return $this->documentLines = $lines;
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidJournalException|InvalidStockMovementException
     */
    private function lineFrom(array $row, int $lineNo, PlaceOfSupply $place): BillLine
    {
        $variant = isset($row['variant_id']) && $row['variant_id'] !== null && $row['variant_id'] !== ''
            ? $this->resolveVariant((int) $row['variant_id'])
            : null;

        $item = $variant?->item ?? $this->resolveItem((int) ($row['item_id'] ?? 0), $lineNo);

        // The nesting has to mean something, exactly as it does on the items API:
        // a line naming item 7 and variant 12-of-item-3 would otherwise bill one
        // thing and take another off the shelf.
        if ($variant !== null && isset($row['item_id']) && (int) $row['item_id'] !== 0
            && (int) $row['item_id'] !== (int) $variant->item_id) {
            throw InvalidJournalException::billLineVariantMismatch($lineNo);
        }

        $quantity = Quantity::of($row['quantity'] ?? 0);

        if (! $quantity->isPositive()) {
            throw InvalidJournalException::billLineQuantity($lineNo);
        }

        // Whether a fraction is meaningful at all is the unit's business — 2.5 kg
        // is ordinary, 2.5 bearings is a mistake — and it is checked here as well
        // as in the stock service, because a *non-stock* line never reaches the
        // stock service and half a bearing is still not a quantity.
        if (! $quantity->fitsUnit($item->base_uom)) {
            throw InvalidStockMovementException::fractionalUnit(
                $variant?->displayLabel() ?? $item->name,
                $quantity->trimmed(),
                $item->base_uom->label(),
            );
        }

        // A stocked item has to say *which* one: stock is counted per variant,
        // and a bill that took "a motor" off the shelf would leave the position
        // of every rating unknowable.
        if ($item->tracksStock() && $variant === null) {
            throw InvalidJournalException::billLineNeedsVariant($lineNo, $item->name);
        }

        return BillLine::of(
            lineNo: $lineNo,
            item: $item,
            variant: $variant,
            quantity: $quantity,
            // The price on the bill, always. `sell_price` is a suggestion the
            // form fills in — a rewind is quoted per job, and a stored list price
            // silently overriding what was agreed would put the wrong figure on
            // the customer's invoice.
            unitPrice: Money::of($row['unit_price'] ?? 0),
            place: $place,
            discount: Money::ofNullable($row['discount'] ?? null) ?? Money::zero(),
            memo: isset($row['memo']) && trim((string) $row['memo']) !== '' ? trim((string) $row['memo']) : null,
        );
    }

    /* ---------------------------------------------------------------------
     | Stock
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, StockChange>
     */
    public function stockChangesFrom(array $input): array
    {
        if ($this->stockChanges !== null) {
            return $this->stockChanges;
        }

        $changes = [];

        foreach ($this->documentLinesFrom($input) as $line) {
            if (! $line->movesStock || $line->variant === null) {
                continue;
            }

            $changes[] = $this->stockChangeFor($line)->withLineNo($line->lineNo);
        }

        return $this->stockChanges = $changes;
    }

    /**
     * The same changes, keyed by the line that produced them — what
     * {@see bodyLines()} needs to put a cost against a description.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, StockChange>
     */
    private function changesByLine(array $input): array
    {
        $keyed = [];

        foreach ($this->stockChangesFrom($input) as $change) {
            if ($change->lineNo !== null) {
                $keyed[$change->lineNo] = $change;
            }
        }

        return $keyed;
    }

    /**
     * The quantity one line moves, and what it is worth.
     */
    abstract protected function stockChangeFor(BillLine $line): StockChange;

    /* ---------------------------------------------------------------------
     | Tax
     |-------------------------------------------------------------------- */

    /**
     * Where the supply happened, from two state codes and nothing else.
     *
     * The workshop's comes from its own GSTIN; the counterparty's from theirs. A
     * party with no state on record is treated as local — see
     * {@see PlaceOfSupply}, which explains why that is the correct default rather
     * than a shrug.
     */
    protected function placeOfSupplyFor(?int $partyId): PlaceOfSupply
    {
        $tenantId = $this->context->current();
        $supplier = $tenantId === null ? null : $this->tenants->findById($tenantId)?->state_code;

        if ($partyId === null) {
            return PlaceOfSupply::domestic($supplier);
        }

        return PlaceOfSupply::between($supplier, $this->parties->findById($partyId)?->state_code);
    }

    /**
     * What the tax line says it is: "CGST + SGST" or "IGST", so a voucher can be
     * read without opening the invoice behind it.
     *
     * @param  array<int, BillLine>  $lines
     */
    private function taxMemo(array $lines): string
    {
        $inter = ($lines[0] ?? null)?->tax->interState ?? false;

        return $inter ? 'IGST' : 'CGST + SGST';
    }

    /* ---------------------------------------------------------------------
     | Resolution
     |-------------------------------------------------------------------- */

    private function resolveVariant(int $id): ItemVariant
    {
        return $this->variants->findWithItem($id)
            ?? throw InvalidStockMovementException::unknownVariant($id);
    }

    private function resolveItem(int $id, int $lineNo): Item
    {
        return $this->items->findById($id)
            ?? throw InvalidJournalException::billLineUnknownItem($lineNo, $id);
    }
}
