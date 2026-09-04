<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\SalaryBasis;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's payslip for one month — M22.
 *
 * Every descriptive field is a snapshot taken at the moment the run posted. The
 * relations below exist for navigation — "open this employee" — and never for
 * reading a figure back: a workshop that raises a wage in November must still
 * see October's payslip saying what October said.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property string $employee_name
 * @property string|null $designation
 * @property SalaryBasis $salary_basis
 * @property string $pay_rate
 * @property int $paid_half_days
 * @property int $period_half_days
 * @property array<string, int> $attendance
 * @property string $gross
 * @property string $advance_recovered
 * @property string $net
 * @property string|null $notes
 */
#[Fillable([
    'tenant_id', 'payroll_run_id', 'employee_id',
    'employee_name', 'designation', 'salary_basis', 'pay_rate',
    'paid_half_days', 'period_half_days', 'attendance',
    'gross', 'advance_recovered', 'net', 'notes',
])]
class PayrollLine extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'salary_basis' => SalaryBasis::class,
            'pay_rate' => 'decimal:2',
            'paid_half_days' => 'integer',
            'period_half_days' => 'integer',
            'attendance' => 'array',
            'gross' => 'decimal:2',
            'advance_recovered' => 'decimal:2',
            'net' => 'decimal:2',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * For navigation only — never for reading a name or a rate back. See the
     * class note.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /* ---------------------------------------------------------------------
     | Service
     |-------------------------------------------------------------------- */

    /** Days paid, as a number a person reads: 19, or 18.5. */
    public function paidDays(): float
    {
        return $this->paid_half_days / 2;
    }

    /** The full month, in the same units. */
    public function periodDays(): float
    {
        return $this->period_half_days / 2;
    }

    /**
     * How many days were actually worked — present plus half days.
     *
     * Not the same as the days paid, and a payslip shows both: a monthly
     * employee is paid for Sundays and did not work them, which is the line a
     * workshop is asked about most often.
     */
    public function attendedDays(): float
    {
        $counts = $this->attendance ?? [];

        return (int) ($counts[AttendanceStatus::Present->value] ?? 0)
            + ((int) ($counts[AttendanceStatus::HalfDay->value] ?? 0)) / 2;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
