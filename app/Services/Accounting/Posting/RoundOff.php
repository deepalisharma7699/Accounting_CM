<?php

namespace App\Services\Accounting\Posting;

use App\Support\Money;

/**
 * The last paise of a bill, taken to the nearest rupee.
 *
 * An 18% GST bill almost never lands on a whole rupee — ₹1,062.36 is the shape
 * of nearly every invoice a workshop writes — and a counter that has to find
 * thirty-six paise for a customer holding a five-hundred-rupee note does not
 * find them. It rounds, writes ₹1,062 on the bill, and the books are then a
 * rupee out unless the difference is *booked* somewhere.
 *
 * That somewhere is {@see \App\Enums\SystemAccount::RoundOff}, and booking it is
 * the whole of this file. What is not here is any change to a line: the taxable
 * values and the GST stay exactly as computed, because they are what goes on the
 * return. Only the document's total moves, and the residue is a posting of its
 * own against it.
 *
 * ## Why the nearest rupee, away from zero at fifty paise
 *
 * Because it is what the trade does and what the department expects — CGST
 * §170 lets the whole tax and the whole amount payable be rounded to the nearest
 * rupee, and "nearest" with fifty paise going up is the ordinary reading. Half
 * to even would be defensible arithmetic and indefensible on a counter: nobody
 * can explain why ₹10.50 became ₹10 and ₹11.50 became ₹12.
 *
 * ## Why this is not applied to a line
 *
 * Rounding each line and adding up gives a different answer from rounding the
 * total, and the difference grows with the number of lines. The invoice total is
 * the figure the customer pays, so it is the figure that is rounded; the lines
 * keep their paise and continue to add up to what was actually taxed.
 *
 * ## Off by default
 *
 * `tenants.round_off_invoices`, off unless a workshop turns it on — see the
 * migration. A rounding policy changes what a customer is charged, and switching
 * one on underneath a workshop that never asked for it is not a default, it is a
 * surprise on their next invoice.
 */
final class RoundOff
{
    /**
     * The whole rupee this amount is nearest to.
     *
     * Integer arithmetic on paise throughout — `round($amount, 0)` on a float is
     * how ₹1,062.5 becomes ₹1,062 on a machine that stored it as 1062.4999.
     */
    public static function applyTo(Money $amount): Money
    {
        $minor = $amount->minor();

        // A credit note's total is positive like an invoice's, so this is
        // belt-and-braces — but "away from zero" has to mean the same thing on
        // both sides of zero or a reversal would not undo what it reversed.
        $sign = $minor < 0 ? -1 : 1;
        $paise = abs($minor);

        $rupees = intdiv($paise, 100);

        if ($paise % 100 >= 50) {
            $rupees++;
        }

        return Money::fromMinor($sign * $rupees * 100);
    }

    /**
     * What has to be booked so the voucher still balances: the rounded total
     * less the real one.
     *
     * Positive where the customer was charged the odd paise up — the workshop
     * gained them, and they are a credit to Round Off. Negative where they were
     * dropped. Between −49 and +50 paise, always.
     */
    public static function residueOn(Money $amount): Money
    {
        return self::applyTo($amount)->minus($amount);
    }
}
