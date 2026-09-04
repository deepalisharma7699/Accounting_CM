<?php

namespace App\Models;

use App\Casts\UnitCast;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use App\Support\Quantity;
use Database\Factories\WorkshopJobPartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bearing, one metre of copper, one hour of rewinding — M19.
 *
 * A row here is a **note about what will be billed**, not a movement. It
 * reserves nothing and takes nothing off the shelf; the bearing leaves stock
 * when the invoice posts, through the posting engine, like everything else. See
 * the `workshop_job_parts` migration for why that invariant is worth more than
 * the reservation it gives up.
 *
 * Once billed, {@see $transaction_line_id} points at the invoice line this
 * became — which is what makes "has this been billed" a fact rather than a flag,
 * and what stops the same bearing being charged for twice.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $workshop_job_id
 * @property int $item_id
 * @property int|null $variant_id
 * @property string $description
 * @property string $quantity
 * @property \App\Support\Units\UnitDefinition $unit
 * @property string $unit_price
 * @property string $discount_amount
 * @property string|null $memo
 * @property int|null $transaction_line_id
 */
#[Fillable([
    'tenant_id', 'workshop_job_id', 'item_id', 'variant_id', 'description',
    'quantity', 'unit', 'unit_price', 'discount_amount', 'memo', 'transaction_line_id',
])]
class WorkshopJobPart extends Model
{
    /** @use HasFactory<WorkshopJobPartFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => UnitCast::class,
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return BelongsTo<WorkshopJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkshopJob::class, 'workshop_job_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<ItemVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'variant_id');
    }

    /**
     * The invoice line this part became, once it has become one.
     *
     * @return BelongsTo<TransactionLine, $this>
     */
    public function transactionLine(): BelongsTo
    {
        return $this->belongsTo(TransactionLine::class, 'transaction_line_id');
    }

    /* ---------------------------------------------------------------------
     | Values
     |-------------------------------------------------------------------- */

    public function quantityValue(): Quantity
    {
        return Quantity::of($this->quantity);
    }

    public function unitPriceMoney(): Money
    {
        return Money::of($this->unit_price);
    }

    public function discountMoney(): Money
    {
        return Money::of($this->discount_amount);
    }

    /**
     * What this part will be billed at, before tax.
     *
     * Before tax, and deliberately: the GST is worked out by the bill template
     * from the item's rate and the two state codes, once, on the server. A tax
     * figure computed here would be a second implementation of arithmetic that
     * ends up on a government return — which is the one thing this codebase
     * refuses to have twice.
     */
    public function lineTotal(): Money
    {
        return $this->quantityValue()
            ->costAt($this->unitPriceMoney())
            ->minus($this->discountMoney());
    }

    public function isBilled(): bool
    {
        return $this->transaction_line_id !== null;
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * What the next invoice off this job would carry.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnbilled(Builder $query): Builder
    {
        return $query->whereNull('transaction_line_id');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
