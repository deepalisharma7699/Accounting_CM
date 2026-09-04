<?php

namespace App\Support;

/**
 * "₹6,701.10" as "Six Thousand Seven Hundred One Rupees and Ten Paise Only".
 *
 * On the invoice because an Indian tax invoice carries it, and it carries it for
 * a reason that predates the software: the figure in words is the one a digit
 * cannot be added to. A workshop handing over a printed bill wants the amount
 * stated twice, and a customer disputing one reads the words.
 *
 * ## Indian grouping, not international
 *
 * Crore and lakh, not million and billion. 1,50,000 is "One Lakh Fifty Thousand"
 * and never "One Hundred Fifty Thousand" — the second is not a translation, it
 * is a different number system, and on a document that may be shown to an
 * assessing officer the local convention is the correct one.
 *
 * ## Why integers throughout
 *
 * The input is paise, from {@see Money::minor()}. Splitting a decimal string
 * would reintroduce the floating-point question this codebase answers everywhere
 * else with integer minor units, and "Ninety-Nine Paise" printing as "Ninety-
 * Eight" for want of a rounding rule is precisely the failure the words are on
 * the document to prevent.
 */
final class AmountInWords
{
    /** @var array<int, string> */
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    /** @var array<int, string> */
    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    /**
     * The Indian groups, largest first, with what each is worth.
     *
     * Note the shape: crore and lakh are *hundreds* of the group below them,
     * where thousand and hundred are tens and hundreds of units. Expressing them
     * as plain multipliers rather than as a repeating rule is what keeps
     * 12,34,56,789 grouping as 12 crore / 34 lakh / 56 thousand / 7 hundred / 89.
     *
     * @var array<string, int>
     */
    private const GROUPS = [
        'Crore' => 10000000,
        'Lakh' => 100000,
        'Thousand' => 1000,
        'Hundred' => 100,
    ];

    /**
     * The full line as it appears on a document.
     *
     * Paise are stated only when there are any. "Rupees and Zero Paise" reads as
     * a machine talking, and on a workshop that rounds to the rupee (see
     * `tenants.round_off_invoices`) it would be on every single invoice.
     *
     * A negative amount is written as its magnitude with the sign said out loud.
     * A credit note is a positive document in its own right — it says what is
     * being credited, not minus what was sold — so this is reached only by a
     * caller that has something genuinely below zero, and silently printing the
     * magnitude would be the worst of the available answers.
     */
    public static function rupees(Money $amount): string
    {
        $minor = $amount->minor();
        $prefix = $minor < 0 ? 'Minus ' : '';
        $minor = abs($minor);

        $rupees = intdiv($minor, 100);
        $paise = $minor % 100;

        $words = self::convert($rupees).' '.($rupees === 1 ? 'Rupee' : 'Rupees');

        if ($paise > 0) {
            $words .= ' and '.self::convert($paise).' '.($paise === 1 ? 'Paisa' : 'Paise');
        }

        return $prefix.$words.' Only';
    }

    /**
     * A whole number in words, with no currency around it.
     */
    public static function convert(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $parts = [];

        foreach (self::GROUPS as $name => $value) {
            $count = intdiv($number, $value);

            if ($count === 0) {
                continue;
            }

            // Recursive, because a crore count is itself a number up to seven
            // digits — 12,34,56,78,90,000 is "Twelve Lakh Thirty Four Thousand
            // Fifty Six Crore …", and a flat pass would have no way to say it.
            $parts[] = self::convert($count).' '.$name;

            $number %= $value;
        }

        if ($number > 0) {
            $parts[] = self::belowHundred($number);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number];
        }

        $tens = self::TENS[intdiv($number, 10)];
        $ones = $number % 10;

        // Hyphenated, as English writes them: "Forty-Two", not "Forty Two".
        return $ones === 0 ? $tens : $tens.'-'.self::ONES[$ones];
    }
}
