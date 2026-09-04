<?php

namespace App\Enums;

use App\Services\Staff\PayrollCalculator;

/**
 * What one person did on one day — M22.
 *
 * Six states, and every one of them earns a different amount of money. That is
 * the test a candidate seventh has to pass: "late", "on site" and "training" are
 * all real things that happen in a workshop and none of them changes what is
 * owed, so recording them here would be putting a diary in the payroll input.
 *
 * ## Why the pay is expressed in half-days
 *
 * Because a half day is the only fraction this trade actually uses, and because
 * halves in integers are exact. `0.5 + 0.5 + 0.5` is not 1.5 in binary floating
 * point, and a month of half days would drift by paise against a figure an
 * employee can check on their fingers. Everything downstream counts half-days
 * and divides once, at the end — the same rule {@see \App\Support\Money} applies
 * to rupees.
 *
 * @see PayrollCalculator, which is the one place these are turned into money.
 */
enum AttendanceStatus: string
{
    /** Worked the day. */
    case Present = 'present';

    /** Worked half of it — the morning, or up to the tea break. */
    case HalfDay = 'half_day';

    /** Did not come, and it is not a leave anybody has agreed to pay for. */
    case Absent = 'absent';

    /**
     * Away, and paid for it: the agreed leave a monthly employee is entitled to.
     *
     * Paid on **both** bases, unlike a holiday or a week off, and that is the
     * distinction the two carry. A rest day is the shop being shut; a paid leave
     * is a day the workshop has agreed to pay for even though the shop was open.
     * Granting one to a daily-wage helper is an unusual thing for a workshop to
     * do — which is exactly why it has to be recorded deliberately rather than
     * inferred from a calendar.
     */
    case PaidLeave = 'paid_leave';

    /** The shop was shut — a festival, a bandh. */
    case Holiday = 'holiday';

    /** The employee's weekly off. */
    case WeekOff = 'week_off';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::HalfDay => 'Half day',
            self::Absent => 'Absent',
            self::PaidLeave => 'Paid leave',
            self::Holiday => 'Holiday',
            self::WeekOff => 'Week off',
        };
    }

    /** The single letter on a month register, where a column is one day wide. */
    public function initial(): string
    {
        return match ($this) {
            self::Present => 'P',
            self::HalfDay => '½',
            self::Absent => 'A',
            self::PaidLeave => 'L',
            self::Holiday => 'H',
            self::WeekOff => 'W',
        };
    }

    /**
     * The Tailwind pair the badge and the register cell are painted in.
     *
     * Held on the enum rather than in a lookup in each of the three screens that
     * needs one, for the reason every other vocabulary in this codebase is held
     * once: the fourth reader is what makes a copy drift.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Present => 'bg-emerald-50 text-emerald-700',
            self::HalfDay => 'bg-amber-50 text-amber-700',
            self::Absent => 'bg-rose-50 text-rose-700',
            self::PaidLeave => 'bg-blue-50 text-blue-700',
            self::Holiday, self::WeekOff => 'bg-muted text-secondary-foreground',
        };
    }

    /**
     * What this day is worth, in half-days, on a given basis. 0, 1 or 2.
     *
     * The one place a status becomes a quantity. Note that only the two rest
     * days differ between the bases — see {@see SalaryBasis::restDayIsPaid()} —
     * and that a paid leave is deliberately paid on both.
     */
    public function paidHalfDays(SalaryBasis $basis): int
    {
        return match ($this) {
            self::Present, self::PaidLeave => 2,
            self::HalfDay => 1,
            self::Absent => 0,
            self::Holiday, self::WeekOff => $basis->restDayIsPaid() ? 2 : 0,
        };
    }

    /**
     * True for the states that mean the person was at work. Used for the "days
     * worked" figure on a payslip, which is not the same as the days paid — a
     * monthly employee is paid for Sundays and did not work them.
     */
    public function isAttended(): bool
    {
        return $this === self::Present || $this === self::HalfDay;
    }

    /**
     * The status the day sheet lands on when somebody marks a whole day at once.
     */
    public static function default(): self
    {
        return self::Present;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string, initial: string, tone: string, attended: bool}>
     */
    public static function catalogue(): array
    {
        return array_map(fn (self $status) => [
            'value' => $status->value,
            'label' => $status->label(),
            'initial' => $status->initial(),
            'tone' => $status->tone(),
            'attended' => $status->isAttended(),
        ], self::cases());
    }
}
