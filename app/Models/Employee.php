<?php

namespace App\Models;

use App\Enums\SalaryBasis;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Staff\PayrollService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody who works for the workshop — M22.
 *
 * **There is no advance balance column here, by design.** What is out with an
 * employee is derived on every read: the staff advances posted against them,
 * less what payroll has already recovered. See
 * {@see PayrollService::advanceOutstandingFor()}. A stored balance is the same
 * mistake as a stored party outstanding or a `qty_on_hand` column — it agrees
 * with the truth right up until one of the two is written without the other, and
 * nobody notices until an employee queries a deduction months later.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property int|null $designation_id
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $notes
 * @property SalaryBasis $salary_basis
 * @property string $pay_rate
 * @property \Illuminate\Support\Carbon $joined_on
 * @property \Illuminate\Support\Carbon|null $left_on
 * @property bool $is_active
 */
#[Fillable([
    'tenant_id', 'name', 'designation_id', 'phone', 'email', 'address', 'notes',
    'salary_basis', 'pay_rate', 'joined_on', 'left_on', 'is_active',
])]
class Employee extends Model
{
    use Auditable, BelongsToTenant;

    /**
     * Everything a workshop can edit, and the pay fields above all.
     *
     * `pay_rate` and `salary_basis` are what payroll multiplies by, and a posted
     * run carries the figure it used but not the reason it was that figure — so
     * without the trail, a raise applied a month early is an overpayment with
     * nothing on any screen to explain it. `left_on` is here for the mirror
     * case: it is what takes somebody off the payroll, and a date typed a month
     * early is an underpayment nobody would think to look for.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return [
            'name', 'designation_id', 'phone', 'email', 'address', 'notes',
            'salary_basis', 'pay_rate', 'joined_on', 'left_on', 'is_active',
        ];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'salary_basis' => SalaryBasis::class,
            // Decimal strings, never floats — the same rule the ledger follows.
            'pay_rate' => 'decimal:2',
            'joined_on' => 'date',
            'left_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return BelongsTo<StaffDesignation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(StaffDesignation::class, 'designation_id');
    }

    /**
     * @return HasMany<StaffAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(StaffAttendance::class);
    }

    /**
     * @return HasMany<PayrollLine, $this>
     */
    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * Every transaction stamped with this employee — which is the advances, and
     * only the advances. A payroll run pays everybody at once and carries no
     * employee at all.
     *
     * @return HasMany<Transaction, $this>
     */
    public function advances(): HasMany
    {
        return $this->hasMany(Transaction::class, 'employee_id');
    }

    /* ---------------------------------------------------------------------
     | Service
     |-------------------------------------------------------------------- */

    /**
     * Was this person on the payroll on the given day?
     *
     * Read from the dates rather than from `is_active`, and the difference is
     * the whole reason both exist. `is_active` is about *now* — whether they
     * appear on today's day sheet. This is about a day in the past, and it has
     * to keep answering correctly for a month that is being run after somebody
     * left. An employee archived in October was still owed for September.
     */
    public function wasInServiceOn(DateTimeInterface|string $date): bool
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        if ($day->lessThan(CarbonImmutable::parse($this->joined_on)->startOfDay())) {
            return false;
        }

        return $this->left_on === null
            || $day->lessThanOrEqualTo(CarbonImmutable::parse($this->left_on)->startOfDay());
    }

    /**
     * True for somebody who has left, whatever their `is_active` flag says.
     *
     * The two are set together by the service, and this reads the date because
     * the date is the fact — a leaving date in the future is somebody working
     * out their notice, and they are still on next week's day sheet.
     */
    public function hasLeft(): bool
    {
        return $this->left_on !== null
            && CarbonImmutable::parse($this->left_on)->startOfDay()->isPast();
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Everybody who was on the payroll at any point in a period — which is not
     * the same set as "active", and payroll needs this one.
     *
     * Somebody who left on the 12th is owed for eleven days of that month and
     * appears on no active list by the time it is run; somebody who joins next
     * month is active today and is owed nothing for this one.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInServiceBetween(Builder $query, DateTimeInterface|string $from, DateTimeInterface|string $to): Builder
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();

        return $query
            ->whereDate('joined_on', '<=', $to)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('left_on')
                ->orWhereDate('left_on', '>=', $from));
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
