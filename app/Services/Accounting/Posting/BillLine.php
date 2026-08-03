<?php

namespace App\Services\Accounting\Posting;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Services\Accounting\Tax\GstBreakdown;
use App\Services\Accounting\Tax\GstRate;
use App\Services\Accounting\Tax\PlaceOfSupply;
use App\Support\Money;
use App\Support\Quantity;

/**
 * One line of a bill, before it is written.
 *
 * The fourth member of the family {@see PostingLine}, {@see PaymentSplit} and
 * {@see StockChange} belong to, and held for the same reason all three are: a
 * line that cannot be constructed inconsistently cannot be posted
 * inconsistently. The taxable value, the tax and the total are computed *here*,
 * once, from the quantity and the price — so there is no arrangement in which a
 * line's own arithmetic disagrees with itself.
 *
 * ## What decides the tax
 *
 * Three things, and none of them is typed by the person writing the bill:
 *
 *   * the **rate** comes from the item, because the rate follows the HSN code
 *     and the HSN code is a property of what the thing *is*;
 *   * the **shape** — CGST+SGST or IGST — comes from two state codes, via
 *     {@see PlaceOfSupply};
 *   * the **base** is quantity × price − discount, because a discount reduces
 *     what was supplied for, and therefore what tax is due on.
 *
 * ## What is *not* here
 *
 * The cost. A line's cost is the value of the stock movement it produced, and
 * that is only knowable at the moment of posting, under a lock. The template
 * pairs the two by line number; see `stock_movements.transaction_line_id`.
 *
 * Immutable, and carries no id: a BillLine describes a line, it is not one.
 */
final class BillLine
{
    private function __construct(
        public readonly int $lineNo,
        public readonly Item $item,
        public readonly ?ItemVariant $variant,
        public readonly string $description,
        public readonly Quantity $quantity,
        public readonly UnitOfMeasure $unit,
        public readonly Money $unitPrice,
        public readonly Money $discount,
        public readonly GstBreakdown $tax,
        public readonly bool $movesStock,
        public readonly ?string $memo = null,
    ) {}

    /**
     * Build a line from a resolved item, a validated quantity and a price.
     *
     * The description is snapshotted rather than joined: a workshop that renames
     * a variant next year must not change what last year's invoice says it sold.
     */
    public static function of(
        int $lineNo,
        Item $item,
        ?ItemVariant $variant,
        Quantity $quantity,
        Money $unitPrice,
        PlaceOfSupply $place,
        Money $discount = null,
        ?string $description = null,
        ?string $memo = null,
    ): self {
        $quantity = $quantity->absolute();
        $discount ??= Money::zero();

        $gross = $quantity->costAt($unitPrice);

        // A discount larger than the line is a typo, not a credit note: it would
        // put a negative taxable value on an invoice and tax owed *to* the
        // customer. Clamped rather than refused, because the intent — "make this
        // line free" — is unambiguous and refusing it helps nobody.
        $taxable = $discount->compareTo($gross) >= 0 ? Money::zero() : $gross->minus($discount);

        return new self(
            lineNo: $lineNo,
            item: $item,
            variant: $variant,
            description: $description ?? self::describe($item, $variant),
            quantity: $quantity,
            unit: $item->base_uom,
            unitPrice: $unitPrice,
            discount: $discount,
            tax: GstBreakdown::on($taxable, GstRate::of($item->gst_rate), $place),
            // The item's own answer, asked once and recorded — see
            // Item::tracksStock(), which pairs capability with the workshop's
            // choice so nothing has to remember both halves.
            movesStock: $item->tracksStock() && $variant !== null,
            memo: $memo,
        );
    }

    /* ---------------------------------------------------------------------
     | Amounts
     |-------------------------------------------------------------------- */

    /** Quantity × price, before any discount. */
    public function gross(): Money
    {
        return $this->quantity->costAt($this->unitPrice);
    }

    /** What tax is charged on: quantity × price − discount. */
    public function taxable(): Money
    {
        return $this->tax->taxable;
    }

    public function taxTotal(): Money
    {
        return $this->tax->total();
    }

    /** Taxable value plus tax — what the line is worth on the invoice. */
    public function total(): Money
    {
        return $this->tax->inclusive();
    }

    /* ---------------------------------------------------------------------
     | Classification
     |-------------------------------------------------------------------- */

    /**
     * Whether this line is labour rather than goods.
     *
     * Decides which revenue account it credits: an hour of rewinding is Service
     * Income and a motor is Sales. Keeping them apart is what lets a workshop
     * see whether it makes its money from parts or from skill — which is the
     * single most useful thing a rewinding shop's P&L can tell it.
     */
    public function isService(): bool
    {
        return $this->item->type === ItemType::Service;
    }

    /**
     * A line the customer would recognise: "5 HP / 3 ph / 1440 RPM", or the item
     * name where there is nothing to vary.
     */
    private static function describe(Item $item, ?ItemVariant $variant): string
    {
        if ($variant === null) {
            return $item->name;
        }

        $label = $variant->displayLabel();

        // A variant labelled the same as its family adds nothing; a variant
        // labelled by its specification reads better with the family in front of
        // it — "Induction Motor · 5 HP / 3 ph / 1440 RPM".
        return $label === $item->name ? $label : sprintf('%s · %s', $item->name, $label);
    }

    /**
     * The storable shape, for `transaction_lines`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->item->id,
            'variant_id' => $this->variant?->id,
            'line_no' => $this->lineNo,
            'description' => $this->description,
            'quantity' => $this->quantity->amount(),
            'unit' => $this->unit->value,
            'unit_price' => $this->unitPrice->amount(),
            'discount_amount' => $this->discount->amount(),
            'taxable_value' => $this->taxable()->amount(),
            // Snapshotted from the item, for the same reason as the description:
            // a code corrected next March must not rewrite an invoice already
            // sent.
            'hsn_sac' => $this->item->hsn_sac,
            'gst_rate' => $this->tax->rate->percent(),
            'cgst_amount' => $this->tax->cgst->amount(),
            'sgst_amount' => $this->tax->sgst->amount(),
            'igst_amount' => $this->tax->igst->amount(),
            'line_total' => $this->total()->amount(),
            'is_stock' => $this->movesStock,
            'memo' => $this->memo,
        ];
    }

    /**
     * @param  array<int, self>  $lines
     * @return array<int, GstBreakdown>
     */
    public static function breakdownsOf(array $lines): array
    {
        return array_map(fn (self $line) => $line->tax, $lines);
    }

    /**
     * The invoice total: every line, tax included.
     *
     * @param  array<int, self>  $lines
     */
    public static function invoiceTotal(array $lines): Money
    {
        return Money::sum(array_map(fn (self $line) => $line->total(), $lines));
    }
}
