<?php

namespace App\Services\Accounting\Tax;

use App\Support\Money;

/**
 * The tax on one taxable value, split the way the invoice has to print it.
 *
 * One of the two shapes, never a mixture:
 *
 * ```
 * intra-state    CGST 9%  +  SGST 9%      both to the state and the centre
 * inter-state    IGST 18%                 one charge, apportioned later
 * ```
 *
 * ## Why the split is stored on the line and not in the ledger
 *
 * Phase 1 has **one** GST Output account and one GST Input account, and the
 * ledger carries the total. The three-way split is document detail in exactly
 * the sense `transaction_payments.mode` is: the return needs it, the trial
 * balance does not, and inventing three accounts per direction would make every
 * workshop's chart harder to read for a distinction their accountant already
 * knows how to make from the invoice.
 *
 * ## Two ways in, one shape out
 *
 * {@see on()} adds tax to a value; {@see within()} takes it out of one. Which
 * applies is the line's own `price_includes_tax`, and nothing downstream of here
 * can tell the difference — the taxable value, the split and the total are the
 * same three fields either way, so the ledger, the invoice and the GST return
 * never learn how the rate happened to be quoted.
 *
 * ## Why the halves are not both rounded
 *
 * ₹762.71 of tax split in two is ₹381.355 each. Rounding both gives ₹381.36 +
 * ₹381.36 = ₹762.72 — a paisa the invoice total does not contain, and the kind of
 * discrepancy that makes a customer's accounts department telephone. So CGST
 * takes the floor and **SGST takes the remainder**, and the two always add back
 * to exactly the tax that was computed.
 *
 * Immutable.
 */
final class GstBreakdown
{
    private function __construct(
        public readonly GstRate $rate,
        public readonly Money $taxable,
        public readonly Money $cgst,
        public readonly Money $sgst,
        public readonly Money $igst,
        public readonly bool $interState,
    ) {}

    /**
     * Tax added on top of a value — a rate quoted before GST.
     */
    public static function on(Money $taxable, GstRate $rate, PlaceOfSupply $place): self
    {
        return self::split($rate, $taxable, $rate->taxOn($taxable), $place);
    }

    /**
     * Tax already inside an amount — a rate quoted with GST in it.
     *
     * The counter's other way of pricing: "₹118" rather than "₹100 plus tax". No
     * tax is added; it is *extracted*, and the figure somebody typed is the
     * figure the customer pays.
     *
     * The tax is the remainder rather than a second multiplication, for the
     * reason {@see GstRate::baseWithin()} sets out: base plus tax has to come back
     * to exactly what was quoted, and two roundings do not reliably do that.
     * Which is the same rule the CGST and SGST halves already follow below — a
     * split has to add back to the thing it split.
     */
    public static function within(Money $inclusive, GstRate $rate, PlaceOfSupply $place): self
    {
        $taxable = $rate->baseWithin($inclusive);

        return self::split($rate, $taxable, $inclusive->minus($taxable), $place);
    }

    /**
     * One tax figure, put into the shape the invoice has to print it in.
     *
     * Shared by both constructors deliberately: the intra/inter-state split and
     * the floor-and-remainder rule for the two halves are the same question
     * whichever way the rate was quoted, and a second copy of them would be a
     * second answer on a government return.
     */
    private static function split(GstRate $rate, Money $taxable, Money $tax, PlaceOfSupply $place): self
    {
        if ($place->isInterState()) {
            return new self($rate, $taxable, Money::zero(), Money::zero(), $tax, true);
        }

        $cgst = $rate->halfOf($tax);

        return new self($rate, $taxable, $cgst, $tax->minus($cgst), Money::zero(), false);
    }

    /**
     * A line that carries no tax at all — an unregistered workshop, or an item
     * whose rate nobody has set.
     */
    public static function none(Money $taxable): self
    {
        return new self(GstRate::zero(), $taxable, Money::zero(), Money::zero(), Money::zero(), false);
    }

    /**
     * The whole tax, however it is split.
     */
    public function total(): Money
    {
        return $this->cgst->plus($this->sgst)->plus($this->igst);
    }

    /**
     * Taxable value plus tax — what the line is actually worth.
     */
    public function inclusive(): Money
    {
        return $this->taxable->plus($this->total());
    }

    public function isZero(): bool
    {
        return $this->total()->isZero();
    }

    /**
     * The totals of several breakdowns, for an invoice footer.
     *
     * Summed rather than recomputed on the invoice total, and that is not the
     * same thing: a bill with an 18% motor and a 12% service has no single rate,
     * and applying one to the sum would produce a number that matches no line.
     *
     * @param  array<int, self>  $breakdowns
     * @return array{taxable: Money, cgst: Money, sgst: Money, igst: Money, tax: Money, total: Money}
     */
    public static function totals(array $breakdowns): array
    {
        $taxable = Money::sum(array_map(fn (self $item) => $item->taxable, $breakdowns));
        $cgst = Money::sum(array_map(fn (self $item) => $item->cgst, $breakdowns));
        $sgst = Money::sum(array_map(fn (self $item) => $item->sgst, $breakdowns));
        $igst = Money::sum(array_map(fn (self $item) => $item->igst, $breakdowns));
        $tax = $cgst->plus($sgst)->plus($igst);

        return [
            'taxable' => $taxable,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'tax' => $tax,
            'total' => $taxable->plus($tax),
        ];
    }

    /**
     * @return array{rate: string, taxable: string, cgst: string, sgst: string, igst: string, tax: string, total: string}
     */
    public function toArray(): array
    {
        return [
            'rate' => $this->rate->percent(),
            'taxable' => $this->taxable->amount(),
            'cgst' => $this->cgst->amount(),
            'sgst' => $this->sgst->amount(),
            'igst' => $this->igst->amount(),
            'tax' => $this->total()->amount(),
            'total' => $this->inclusive()->amount(),
        ];
    }
}
