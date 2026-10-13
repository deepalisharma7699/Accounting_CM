<?php

namespace App\Services\Accounting\Posting;

use App\Support\Money;

/**
 * The last paise of a bill, dropped.
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
 * ## Why always down
 *
 * Because that is what the counter does: ₹1,250.80 is "twelve fifty", never
 * "twelve fifty-one", and a bill that asked for the extra paise would be argued
 * down to the same figure at the till. So the paise are given away, every time,
 * and the bill says so on a line of its own. CGST §170 permits rounding the
 * amount payable; nothing requires the rounding to be to the nearest rupee.
 *
 * It was the nearest rupee until October 2026, and a document posted before
 * then may carry a round-off of up to +50 paise. Nothing re-reads it: a
 * reversal mirrors the entries it finds, and the invoice restates the figure
 * from the stored total.
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
     * The whole rupee at or below this amount — its paise dropped.
     *
     * Integer arithmetic on paise throughout — `floor()` on a float is how
     * ₹1,063.00 becomes ₹1,062 on a machine that stored it as 1062.9999.
     */
    public static function applyTo(Money $amount): Money
    {
        $minor = $amount->minor();

        // A credit note's total is positive like an invoice's, so this is
        // belt-and-braces — but "drop the paise" has to mean the same thing on
        // both sides of zero or a reversal would not undo what it reversed.
        $sign = $minor < 0 ? -1 : 1;

        return Money::fromMinor($sign * intdiv(abs($minor), 100) * 100);
    }

    /**
     * What has to be booked so the voucher still balances: the rounded total
     * less the real one.
     *
     * Never positive: the paise are only ever dropped, so the workshop gives
     * them away and they are a debit to Round Off. Between −99 paise and nil.
     */
    public static function residueOn(Money $amount): Money
    {
        return self::applyTo($amount)->minus($amount);
    }
}
