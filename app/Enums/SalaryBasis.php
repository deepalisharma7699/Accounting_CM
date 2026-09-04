<?php

namespace App\Enums;

use App\Services\Staff\PayrollCalculator;

/**
 * How an employee's pay is *measured* — M22.
 *
 * A workshop pays two kinds of people. A fitter on ₹18,000 a month is paid that
 * whether the month is February or March, and a day missed is a deduction from
 * a figure that already exists. A helper on ₹550 a day earns nothing until they
 * turn up, and there is no monthly figure to deduct from at all.
 *
 * That difference is not a preference or a label. It decides what the
 * denominator of the arithmetic is, and it decides what an *unmarked* day means
 * — see {@see unmarkedDayIsPaid()}, which is the whole reason this is code and
 * not a row somebody maintains. A designation is data; this is not.
 *
 * @see PayrollCalculator for the one place the arithmetic lives.
 */
enum SalaryBasis: string
{
    /**
     * A fixed monthly salary, pro-rated over the days of the month.
     *
     * ₹18,000 in a 30-day month is ₹600 a day, and in a 31-day month it is
     * ₹580.65 — the *salary* does not change, so the day rate must. Pro-rating
     * against a fixed 30 would pay a month's salary and a day extra every March.
     */
    case Monthly = 'monthly';

    /**
     * A rate per day worked. There is no monthly figure; the month's pay is
     * whatever the attendance sheet adds up to.
     */
    case Daily = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly salary',
            self::Daily => 'Daily wage',
        };
    }

    /** What the rate field is called, so the form asks for the thing being set. */
    public function rateLabel(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly salary',
            self::Daily => 'Rate per day',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Daily => 'Per day',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Monthly => 'Paid the same every month. Absences are deducted from it.',
            self::Daily => 'Paid for the days worked. Nothing is owed for a day nobody marked.',
        };
    }

    /**
     * What an **unmarked** day is worth, and the most consequential line in this
     * file.
     *
     * A workshop does not mark attendance every single day — somebody is away,
     * the sheet is filled in on Saturday, the month gets busy. So payroll has to
     * decide what silence means, and the honest answer is different for the two
     * bases:
     *
     *   Monthly — **paid.** A monthly salary is owed unless something is
     *             recorded against it. Treating silence as absence would dock a
     *             fitter three days' pay because nobody opened the screen on
     *             Diwali week, and the employee would be the one to discover it.
     *
     *   Daily   — **not paid.** A daily wage is earned by turning up, and the
     *             mark is the evidence that somebody did. Treating silence as a
     *             day worked would pay a helper for a fortnight nobody can
     *             account for, and there would be nothing on the sheet to
     *             question it against.
     *
     * Both defaults fail towards the thing that gets noticed: an underpayment is
     * raised the same afternoon, an overpayment is not raised at all.
     */
    public function unmarkedDayIsPaid(): bool
    {
        return $this === self::Monthly;
    }

    /**
     * Whether a workshop holiday or a week off is *paid* on this basis.
     *
     * Yes for a monthly salary, which is what "monthly" means — a Sunday is not
     * a deduction. No for a daily wage, which is the standard arrangement in
     * this trade: a helper is paid for days worked, and a shop holiday is a day
     * nobody worked.
     */
    public function restDayIsPaid(): bool
    {
        return $this === self::Monthly;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string, short_label: string, rate_label: string, description: string}>
     */
    public static function catalogue(): array
    {
        return array_map(fn (self $basis) => [
            'value' => $basis->value,
            'label' => $basis->label(),
            'short_label' => $basis->shortLabel(),
            'rate_label' => $basis->rateLabel(),
            'description' => $basis->description(),
        ], self::cases());
    }
}
