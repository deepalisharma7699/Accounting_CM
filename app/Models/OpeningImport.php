<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The receipt for one go-live declaration.
 *
 * **Nothing here is an authority on a balance.** Every figure is a copy of what
 * the ledger already holds, kept because the *decision* is not recoverable from
 * `journal_entries` afterwards: which file, on what day, by whom, how many rows
 * were skipped because they were already in. Ask this what was declared; ask the
 * trial balance what is true.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $filename
 * @property string $fingerprint
 * @property Carbon $date
 * @property int $row_count
 * @property int $imported_count
 * @property int $skipped_count
 * @property string $stock_value
 * @property string $receivable_total
 * @property string $payable_total
 * @property string $other_total
 * @property int $items_created
 * @property int $parties_created
 * @property int|null $created_by
 */
#[Fillable([
    'tenant_id', 'filename', 'fingerprint', 'date',
    'row_count', 'imported_count', 'skipped_count',
    'stock_value', 'receivable_total', 'payable_total', 'other_total',
    'items_created', 'parties_created', 'created_by',
])]
class OpeningImport extends Model
{
    use BelongsToTenant;

    /**
     * Deliberately no HasFactory. An import record with no postings behind it
     * is a receipt for something that never happened — the same reasoning that
     * keeps a factory off {@see JournalEntry} and {@see StockMovement}.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'stock_value' => 'decimal:2',
            'receivable_total' => 'decimal:2',
            'payable_total' => 'decimal:2',
            'other_total' => 'decimal:2',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * The transactions this import posted.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'opening_import_id')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ---------------------------------------------------------------------
     | Amounts
     |-------------------------------------------------------------------- */

    public function stockValueMoney(): Money
    {
        return Money::of($this->stock_value);
    }

    public function receivableMoney(): Money
    {
        return Money::of($this->receivable_total);
    }

    public function payableMoney(): Money
    {
        return Money::of($this->payable_total);
    }

    public function otherMoney(): Money
    {
        return Money::of($this->other_total);
    }

    /**
     * What this import declared, in total — the same figure as the sum of its
     * transactions' totals, and stated here so a history list is one query.
     */
    public function declaredMoney(): Money
    {
        return $this->stockValueMoney()
            ->plus($this->receivableMoney())
            ->plus($this->payableMoney())
            ->plus($this->otherMoney());
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
