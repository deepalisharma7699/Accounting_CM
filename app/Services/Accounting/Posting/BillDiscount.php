<?php

namespace App\Services\Accounting\Posting;

use App\Services\Accounting\Tax\GstRate;
use App\Support\Money;

/**
 * Money off a bill — stated either way, and spread to the paisa.
 *
 * §4.4 names discount arithmetic as one of the things that must live in exactly
 * one place, and this is the place. Two rules, both small and both easy to get
 * subtly wrong:
 *
 *   1. **a percentage resolves to rupees on the server**, never in the browser;
 *   2. **a discount on the whole bill is apportioned across its lines** before
 *      the tax is worked out, and the shares add back to exactly what was given.
 *
 * ## Why the percentage is resolved here and not on the client
 *
 * Because 10% of ₹4,237.29 is where JavaScript stops being able to count.
 * `4237.29 * 0.1` is 423.72900000000004, and a browser that rounds it is a
 * second implementation of the arithmetic the server already owns — the same
 * argument {@see \App\Services\Accounting\BillPreviewService} makes about
 * totals, and it holds harder here because a discount is not merely *displayed*
 * from the client's figure, it is *applied* from it.
 *
 * So a form sends `discount` **or** `discount_percent`, never both, and never a
 * percentage it has already worked out. The requests refuse the pair outright.
 *
 * ## Why a bill discount cannot simply sit on the invoice footer
 *
 * Because it is a discount *before* tax, and GST is charged per line at the
 * line's own rate. A workshop billing an 18% motor and a 12% rewind, then taking
 * ₹1,000 off the bottom, has reduced the taxable value of both — by how much is
 * a question only apportionment answers. Deducting it after tax would charge the
 * customer GST on money they were never asked for, and would put a figure on the
 * GST return that no line supports.
 *
 * So it is pushed *into* the lines: each line's discount grows by its share, the
 * tax follows automatically, and `transaction_lines.discount_amount` ends up
 * holding what that line was really given. That last part is not incidental —
 * {@see \App\Services\Accounting\ReturnService} pro-rates a credit note from
 * exactly that column, so a half return of a bill-discounted line credits half
 * of the bill discount too, with nothing anywhere having to know it was ever a
 * bill-level figure.
 *
 * ## Largest remainder, and why not "round each and hope"
 *
 * ₹1,000 across three lines of ₹1,000, ₹1,000 and ₹1,000 is ₹333.33 three times
 * — ₹999.99, a paisa the customer was promised and did not get. Rounding up
 * instead gives ₹1,000.02 and a bill that does not add up. So each line takes
 * the floor of its exact share and the odd paise go, one each, to the lines with
 * the largest fractional part. The shares always sum to the discount exactly.
 *
 * The same rule an election uses to hand out seats, and the same one
 * {@see \App\Services\Accounting\Tax\GstBreakdown} applies to the CGST/SGST
 * halves for the same reason: a split has to add back to the thing it split.
 */
final class BillDiscount
{
    /**
     * A discount stated as rupees or as a percentage, as rupees.
     *
     * The percentage wins where both arrive, but that is a safety net rather
     * than a rule anybody should rely on: the requests refuse the pair, because
     * "₹100 or 10%?" is a question with a right answer that the payload has
     * failed to state, and picking one silently is how a bill comes to carry a
     * discount nobody chose.
     */
    public static function resolve(
        Money $base,
        int|string|float|null $amount = null,
        int|string|float|null $percent = null,
    ): Money {
        if ($percent !== null && $percent !== '') {
            return self::percentOf($base, $percent);
        }

        return Money::ofNullable($amount) ?? Money::zero();
    }

    /**
     * One discount, spread over the bases it applies to.
     *
     * Returns a share per base, in the same order, always summing to exactly the
     * discount — except where the discount would swallow the bill whole, in
     * which case each line gives up its entire base and the bill comes to
     * nothing. That is a real thing a workshop does (a full warranty job billed
     * at zero) and it is clamped rather than refused, exactly as an oversized
     * line discount is in {@see BillLine::of()}.
     *
     * @param  array<int, Money>  $bases
     * @return array<int, Money>
     */
    public static function apportion(Money $discount, array $bases): array
    {
        $zero = array_map(fn () => Money::zero(), $bases);

        if ($bases === [] || ! $discount->isPositive()) {
            return $zero;
        }

        $total = Money::sum($bases);

        // Nothing to take it off. Every line is already free, and a discount
        // apportioned over a base of zero would divide by it.
        if (! $total->isPositive()) {
            return $zero;
        }

        // More off than there is on. Each line surrenders its whole base, which
        // leaves a document worth nothing rather than one with a negative
        // taxable value and tax owed *to* the customer.
        if ($discount->compareTo($total) >= 0) {
            return $bases;
        }

        $wanted = $discount->minor();
        $whole = $total->minor();

        $shares = [];
        $remainders = [];
        $given = 0;

        foreach ($bases as $key => $base) {
            // Paise times paise before the division, so the exact share is
            // reached from integers and only then cut down — the same shape as
            // GstRate::taxOn(), and for the same reason.
            $exact = $base->minor() * $wanted;

            $shares[$key] = intdiv($exact, $whole);
            $remainders[$key] = $exact % $whole;
            $given += $shares[$key];
        }

        // Ordered by what each line was short of a whole paisa. PHP's sort is
        // stable, so lines that were equally short keep their order on the bill
        // and the first of them is the one that gains — which makes the split
        // reproducible rather than merely correct.
        $order = array_keys($remainders);
        usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a]);

        foreach (array_slice($order, 0, $wanted - $given) as $key) {
            $shares[$key]++;
        }

        return array_map(fn (int $minor) => Money::fromMinor($minor), $shares);
    }

    /**
     * A percentage of an amount, in integers, rounded once.
     *
     * Borrowed from {@see GstRate} rather than written again. A discount
     * percentage and a tax percentage are the same operation on the same types —
     * basis points times paise, divided back down by ten thousand — and the only
     * thing that differs is what the answer is called at the call site. This
     * method supplies that name; a second copy of the arithmetic would supply a
     * second answer.
     */
    private static function percentOf(Money $base, int|string|float $percent): Money
    {
        return GstRate::of($percent)->taxOn($base);
    }
}
