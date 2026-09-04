<?php

namespace App\Services\Staff;

use App\Enums\SalaryBasis;
use App\Models\Employee;
use App\Support\Money;

/**
 * What one person earned in one month, before anything is recovered from it.
 *
 * The output of {@see PayrollCalculator}, and a value object rather than an
 * array for the reason {@see \App\Services\Accounting\Posting\PostingLine} is
 * one: it is handed to a preview, to a posting and to a stored payslip, and all
 * three have to be reading the same figures rather than three interpretations of
 * a loose array.
 *
 * Nothing here has touched the database, and nothing here knows about advances.
 * Recovery is a decision somebody makes on the screen, applied afterwards — see
 * {@see PayrollService}.
 */
final class PayrollComputation
{
    /**
     * @param  array<string, int>  $attendance  How many days of each status were
     *                             marked, plus `unmarked` for the days nobody
     *                             touched. Every key is an
     *                             {@see \App\Enums\AttendanceStatus} value except
     *                             that one.
     * @param  int  $paidHalfDays  What was earned, in half-days.
     * @param  int  $periodHalfDays  What the whole month was worth, in half-days
     *                             — the denominator a monthly salary is
     *                             pro-rated over. Context rather than arithmetic
     *                             for a daily wage, which multiplies its rate.
     * @param  int  $eligibleHalfDays  The part of the month this person was
     *                             actually in service for. Equal to
     *                             `$periodHalfDays` for everybody who was there
     *                             all month, and the figure that explains a
     *                             part-month joiner's pay without anybody having
     *                             to work out why it is short.
     */
    public function __construct(
        public readonly Employee $employee,
        public readonly SalaryBasis $basis,
        public readonly Money $rate,
        public readonly array $attendance,
        public readonly int $paidHalfDays,
        public readonly int $periodHalfDays,
        public readonly int $eligibleHalfDays,
        public readonly Money $gross,
    ) {}

    /** Days paid, as a number a person reads: 19, or 18.5. */
    public function paidDays(): float
    {
        return $this->paidHalfDays / 2;
    }

    public function periodDays(): float
    {
        return $this->periodHalfDays / 2;
    }

    public function eligibleDays(): float
    {
        return $this->eligibleHalfDays / 2;
    }

    /** True for somebody who was not on the payroll for any of this month. */
    public function wasNotInService(): bool
    {
        return $this->eligibleHalfDays === 0;
    }

    /** How many days were left unmarked — the figure a workshop should look at. */
    public function unmarkedDays(): float
    {
        return ($this->attendance['unmarked'] ?? 0);
    }

    /**
     * The payslip's storable shape. Consumed by
     * {@see \App\Repositories\Contracts\PayrollRepositoryInterface::writeLines()}
     * with the recovery filled in on top.
     *
     * @return array<string, mixed>
     */
    public function toLine(): array
    {
        return [
            'employee_id' => (int) $this->employee->id,
            'employee_name' => $this->employee->name,
            'designation' => $this->employee->designation?->name,
            'salary_basis' => $this->basis->value,
            'pay_rate' => $this->rate->amount(),
            'paid_half_days' => $this->paidHalfDays,
            'period_half_days' => $this->periodHalfDays,
            'attendance' => $this->attendance,
            'gross' => $this->gross->amount(),
        ];
    }
}
