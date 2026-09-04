<?php

namespace App\Services\Staff;

use App\Enums\AttendanceStatus;
use App\Enums\SalaryBasis;
use App\Models\Employee;
use App\Models\StaffAttendance;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Turning an attendance sheet into money — M22, and the only place it happens.
 *
 * The preview screen, the posting, and the payslip that is stored against the
 * run all go through this one class. That is CLAUDE.md §4.4 applied to wages:
 * three implementations of "what is Ramesh owed for September" would disagree
 * eventually, and the disagreement would surface as an employee holding a
 * payslip that does not match what they were handed.
 *
 * Nothing here reads or writes the database. It is given an employee, a month
 * and that month's marks, and it returns a figure — which is what makes it
 * testable against the cases that actually go wrong: the part-month joiner, the
 * unmarked fortnight, the 28-day February.
 *
 * ## The three rules
 *
 * **1. Halves, in integers, until the last step.** A half day is the only
 * fraction this trade uses, and `0.5 + 0.5 + 0.5` is not 1.5 in binary floating
 * point. Everything is counted in half-days and divided exactly once, at the
 * end. The same rule {@see Money} applies to rupees, for the same reason.
 *
 * **2. The month is its own denominator.** A ₹18,000 salary is ₹600 a day in a
 * 30-day month and ₹580.65 in a 31-day one. Pro-rating against a fixed 30 would
 * quietly pay a month's salary plus a day every March, May, July, August,
 * October, December and January.
 *
 * **3. An unmarked day is not a blank.** It means "paid" for a monthly salary
 * and "not paid" for a daily wage — see {@see SalaryBasis::unmarkedDayIsPaid()},
 * which is where that decision is made and argued. This class only applies it.
 */
class PayrollCalculator
{
    /**
     * What each person on a list earned in a month.
     *
     * The marks are handed in rather than fetched so that a caller reads them
     * once for the whole sheet — a workshop of nine people over a month is one
     * query, not nine.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, StaffAttendance>  $marks  every mark in the month,
     *                                          for any of them, in any order.
     * @return array<int, PayrollComputation>  keyed by employee id, in the order
     *                                         the employees arrived.
     */
    public function forMonth(Collection $employees, DateTimeInterface|string $month, Collection $marks): array
    {
        $start = self::monthStart($month);
        $byEmployee = $this->index($marks);

        $computed = [];

        foreach ($employees as $employee) {
            $computed[(int) $employee->id] = $this->forEmployee(
                $employee,
                $start,
                $byEmployee[(int) $employee->id] ?? [],
            );
        }

        return $computed;
    }

    /**
     * One person, one month.
     *
     * @param  array<string, AttendanceStatus>  $marks  keyed by `Y-m-d`.
     */
    public function forEmployee(Employee $employee, DateTimeInterface|string $month, array $marks): PayrollComputation
    {
        $start = self::monthStart($month);
        $end = $start->endOfMonth()->startOfDay();

        $basis = $employee->salary_basis;
        $rate = Money::of($employee->pay_rate ?? 0);

        // The month in half-days: the denominator a monthly salary is divided by,
        // and the figure that makes February short and July long, correctly.
        $periodHalfDays = $start->daysInMonth * 2;

        /*
        | The part of the month this person was actually on the payroll for.
        |
        | Both ends matter and both are read from the dates rather than from
        | `is_active` — an employee archived in October was still owed for
        | September, and somebody working out their notice is still owed for next
        | week. See Employee::wasInServiceOn().
        */
        $from = $this->laterOf($start, $employee->joined_on);
        $to = $employee->left_on === null
            ? $end
            : $this->earlierOf($end, $employee->left_on);

        $attendance = ['unmarked' => 0];
        $paidHalfDays = 0;
        $eligibleHalfDays = 0;

        if (! $from->greaterThan($to)) {
            for ($day = $from; ! $day->greaterThan($to); $day = $day->addDay()) {
                $eligibleHalfDays += 2;

                $status = $marks[$day->toDateString()] ?? null;

                if ($status === null) {
                    // Silence, and what it means depends on how they are paid.
                    $attendance['unmarked']++;
                    $paidHalfDays += $basis->unmarkedDayIsPaid() ? 2 : 0;

                    continue;
                }

                $attendance[$status->value] = ($attendance[$status->value] ?? 0) + 1;
                $paidHalfDays += $status->paidHalfDays($basis);
            }
        }

        return new PayrollComputation(
            employee: $employee,
            basis: $basis,
            rate: $rate,
            attendance: $attendance,
            paidHalfDays: $paidHalfDays,
            periodHalfDays: $periodHalfDays,
            eligibleHalfDays: $eligibleHalfDays,
            gross: $this->gross($basis, $rate, $paidHalfDays, $periodHalfDays),
        );
    }

    /**
     * The one line of arithmetic the whole module rests on.
     *
     *   monthly — rate × paid half-days ÷ the month's half-days
     *   daily   — rate × paid half-days ÷ 2
     *
     * Integer paise throughout, rounded half away from zero exactly once at the
     * end. Doing the division first, or in Money, would round twice and leave a
     * sheet of nine people whose lines do not add up to the total posted against
     * them — which is the failure a workshop finds by counting notes.
     */
    private function gross(SalaryBasis $basis, Money $rate, int $paidHalfDays, int $periodHalfDays): Money
    {
        if ($paidHalfDays <= 0 || $rate->isZero()) {
            return Money::zero();
        }

        $divisor = $basis === SalaryBasis::Monthly ? $periodHalfDays : 2;

        if ($divisor <= 0) {
            return Money::zero();
        }

        return Money::fromMinor(self::divideRoundingHalfUp($rate->minor() * $paidHalfDays, $divisor));
    }

    /**
     * `round($numerator / $denominator)`, half away from zero, in integers.
     *
     * `(int) round()` would take the pair through a float, and a monthly salary
     * over 31 days is exactly the sort of division that lands a hair under the
     * midpoint and rounds the wrong way. Both arguments are non-negative here —
     * a rate cannot be negative and half-days cannot be — but the sign is
     * handled anyway rather than assumed, because an assumption in this method
     * is a rupee somebody has to find.
     */
    private static function divideRoundingHalfUp(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) === ($denominator < 0) ? 1 : -1;

        $numerator = abs($numerator);
        $denominator = abs($denominator);

        return $sign * intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    /**
     * Marks by employee, then by date — the shape {@see forEmployee()} reads.
     *
     * @param  Collection<int, StaffAttendance>  $marks
     * @return array<int, array<string, AttendanceStatus>>
     */
    private function index(Collection $marks): array
    {
        $indexed = [];

        foreach ($marks as $mark) {
            $indexed[(int) $mark->employee_id][$mark->date->toDateString()] = $mark->status;
        }

        return $indexed;
    }

    private function laterOf(CarbonImmutable $left, DateTimeInterface|string $right): CarbonImmutable
    {
        $right = CarbonImmutable::parse($right)->startOfDay();

        return $right->greaterThan($left) ? $right : $left;
    }

    private function earlierOf(CarbonImmutable $left, DateTimeInterface|string $right): CarbonImmutable
    {
        $right = CarbonImmutable::parse($right)->startOfDay();

        return $right->lessThan($left) ? $right : $left;
    }

    /**
     * The first day of the month something falls in — the canonical form of a
     * payroll period, and the form `payroll_runs.period_month` stores.
     */
    public static function monthStart(DateTimeInterface|string $month): CarbonImmutable
    {
        return CarbonImmutable::parse($month)->startOfMonth()->startOfDay();
    }
}
