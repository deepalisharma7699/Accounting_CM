<?php

namespace App\Services\Accounting\Posting;

use App\Support\Money;

/**
 * The last paise of a bill, taken to the nearest rupee.
 *
 * An 18% GST bill almost never lands on a whole rupee — ₹1,062.36 is the shape
 * of nearly every invoice a workshop writes — and a counter that has to find
 * thirty-six paise for a customer holding a five-hundred-rupee note does not
 * find them. It writes ₹1,062 on the bill, and the books are then thirty-six
 * paise out unless the difference is *booked* somewhere.
 *
 * That somewhere is {@see \App\Enums\SystemAccount::RoundOff}, and booking it is
 * the whole of this file. What is not here is any change to a line: the taxable
 * values and the GST stay exactly as computed, because they are what goes on the
 * return. Only the document's total moves, and the residue is a posting of its
 * own against it.
 *
 * ## Why the nearest rupee
 *
 * Because that is the standard every invoice the workshop receives follows:
 * fifty paise and over goes up, under fifty goes down, so ₹1,250.80 is ₹1,251
 * and ₹1,250.30 is ₹1,250. The Round Off account is then the running net of
 * paise gained and given, which over a year comes to roughly nothing. CGST §170
 * permits rounding the amount payable to the nearest rupee.
 *
 * It was always *down* for part of October 2026, so a document posted in that
 * window carries a round-off of up to −99 paise. Nothing re-reads it: a
 * reversal mirrors the entries it finds, and the invoice restates the figure
 * from the stored total.
 *
 * A vendor's bill is not ours to round. Where the operator types the total the
 * vendor printed, the purchase takes that figure instead — see
 * {@see \App\Services\Accounting\Posting\Templates\BillTemplate::charge()}.
 *
 * ## Why this is not applied to a line
 *
 * Rounding each line and adding up gives a different answer from rounding the
 * total, and the difference grows with the number of lines. The invoice total is
 * the figure the customer pays, so it is the figure that is rounded; the lines
 * keep their paise and continue to add up to what was actually taxed.
 *
 * ## On for a new workshop, never switched on underneath an old one
 *
 * `tenants.round_off_invoices`. A workshop provisioned from October 2026 starts
 * with it on ({@see \App\Services\Tenancy\TenantService}); one that existed
 * before keeps whatever it chose, and turns it on in Settings. A rounding policy
 * changes what a customer is charged, and switching one on underneath a
 * workshop that never asked for it is not a default, it is a surprise on their
 * next invoice.
 */
final class RoundOff
{
    /**
     * The nearest whole rupee to this amount — fifty paise and over rounds up.
     *
     * Integer arithmetic on paise throughout — `round()` on a float is how
     * ₹1,062.50 becomes ₹1,062 on a machine that stored it as 1062.4999.
     */
    public static function applyTo(Money $amount): Money
    {
        $minor = $amount->minor();

        // A credit note's total is positive like an invoice's, so this is
        // belt-and-braces — but "the nearest rupee" has to mean the same thing
        // on both sides of zero or a reversal would not undo what it reversed.
        $sign = $minor < 0 ? -1 : 1;

        return Money::fromMinor($sign * intdiv(abs($minor) + 50, 100) * 100);
    }

    /**
     * What has to be booked so the voucher still balances: the rounded total
     * less the real one.
     *
     * Between −49 and +50 paise: negative where the paise were given away,
     * positive where they were rounded up.
     */
    public static function residueOn(Money $amount): Money
    {
        return self::applyTo($amount)->minus($amount);
    }
}
