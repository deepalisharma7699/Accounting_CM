<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One month's payroll, as it was paid — M22.
 *
 * ## Not `Auditable`, for the reason transactions are not
 *
 * A posted run cannot be edited. Its figures are snapshots on `payroll_lines`,
 * its money is a posted transaction that refuses writes, and the only transition
 * it has is posted to reversed — which writes a *new* transaction rather than
 * altering this one. "Who changed this figure" has no answer because nothing
 * changes a figure, and an audit row restating what the ledger already records
 * would be a second copy of the truth. `created_by` and `posted_at` sit on the
 * row itself, which answers the question that can be asked.
 *
 * @property int $id
 * @property int $tenant_id
 * @property \Illuminate\Support\Carbon $period_month
 * @property PayrollRunStatus $status
 * @property string|null $notes
 * @property int|null $transaction_id
 * @property \Illuminate\Support\Carbon|null $posted_at
 * @property int|null $created_by
 */
#[Fillable([
    'tenant_id', 'period_month', 'status', 'notes',
    'transaction_id', 'posted_at', 'created_by',
])]
class PayrollRun extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'status' => PayrollRunStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return HasMany<PayrollLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class)->orderBy('employee_name');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ---------------------------------------------------------------------
     | Service
     |-------------------------------------------------------------------- */

    /** "September 2026" — what a workshop calls the run. */
    public function periodLabel(): string
    {
        return $this->period_month->format('F Y');
    }

    /** `2026-09`, which is what the month picker and the API speak. */
    public function periodKey(): string
    {
        return $this->period_month->format('Y-m');
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /**
     * The month's totals, summed from the lines this run holds.
     *
     * Derived rather than stored, and the totals on the transaction are the
     * check: `gross` is what the posting debited to Salary Expense, so if these
     * ever disagreed with it the engine's balance assertion would already have
     * refused the posting. There is nothing to keep in step.
     *
     * @return array{gross: string, advance_recovered: string, net: string, headcount: int}
     */
    public function totals(): array
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return [
            'gross' => Money::sum($lines->map(fn (PayrollLine $line) => Money::of($line->gross)))->amount(),
            'advance_recovered' => Money::sum(
                $lines->map(fn (PayrollLine $line) => Money::of($line->advance_recovered))
            )->amount(),
            'net' => Money::sum($lines->map(fn (PayrollLine $line) => Money::of($line->net)))->amount(),
            'headcount' => $lines->count(),
        ];
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', PayrollRunStatus::Posted->value);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
