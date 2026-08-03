<?php

namespace App\Models;

use App\Enums\UnitOfMeasure;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Accounting\Tax\GstRate;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One line of a bill: what was supplied, how much of it, at what price, with
 * what tax.
 *
 * The *document*, as distinct from its accounting. A bill's journal entries say
 * "credit Sales ₹4,237.29"; this says "three 5 HP motors at ₹1,412.43, HSN 8501,
 * 18%". Both are needed and neither substitutes for the other.
 *
 * **There is no cost column.** A line's cost is its stock movement's value —
 * `stock_movements.transaction_line_id` points back here — so a margin is a join
 * rather than a second copy that could drift. See {@see cost()} and
 * {@see margin()}.
 *
 * Written once, by the posting engine, inside the same database transaction as
 * the entries and the movements. A posted transaction is immutable, so nothing
 * here is ever updated; a draft has no rows in this table at all, because its
 * lines are re-composed — and therefore re-priced and re-taxed — at the moment it
 * is posted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $transaction_id
 * @property int $item_id
 * @property int|null $variant_id
 * @property int $line_no
 * @property string $description
 * @property string $quantity
 * @property UnitOfMeasure $unit
 * @property string $unit_price
 * @property string $discount_amount
 * @property string $taxable_value
 * @property string|null $hsn_sac
 * @property string $gst_rate
 * @property string $cgst_amount
 * @property string $sgst_amount
 * @property string $igst_amount
 * @property string $line_total
 * @property bool $is_stock
 * @property string|null $memo
 */
#[Fillable([
    'tenant_id', 'transaction_id', 'item_id', 'variant_id', 'line_no',
    'description', 'quantity', 'unit', 'unit_price', 'discount_amount',
    'taxable_value', 'hsn_sac', 'gst_rate',
    'cgst_amount', 'sgst_amount', 'igst_amount', 'line_total',
    'is_stock', 'memo',
])]
class TransactionLine extends Model
{
    use BelongsToTenant;

    /**
     * A line is written once and never touched again — the transaction it
     * belongs to is immutable the moment it is posted — so a column claiming to
     * record its last modification would be a permanent lie.
     */
    public const UPDATED_AT = null;

    /**
     * Deliberately no HasFactory, for the same reason {@see JournalEntry} and
     * {@see StockMovement} have none: a bill line that did not come from the
     * posting engine is an invoice with no accounting behind it.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => UnitOfMeasure::class,
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_value' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'is_stock' => 'boolean',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
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
     * The quantity this line took off the shelf, and what it was worth.
     *
     * One movement at most: a line supplies one variant, and a bill that both
     * issued and received the same thing would be two lines.
     *
     * @return HasOne<StockMovement, $this>
     */
    public function stockMovement(): HasOne
    {
        return $this->hasOne(StockMovement::class);
    }

    /* ---------------------------------------------------------------------
     | Amounts
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

    public function taxableMoney(): Money
    {
        return Money::of($this->taxable_value);
    }

    public function taxMoney(): Money
    {
        return Money::of($this->cgst_amount)
            ->plus(Money::of($this->sgst_amount))
            ->plus(Money::of($this->igst_amount));
    }

    public function totalMoney(): Money
    {
        return Money::of($this->line_total);
    }

    public function rate(): GstRate
    {
        return GstRate::of($this->gst_rate);
    }

    public function isInterState(): bool
    {
        return ! Money::of($this->igst_amount)->isZero();
    }

    /* ---------------------------------------------------------------------
     | Margin
     |-------------------------------------------------------------------- */

    /**
     * What this line cost the workshop, from the stock movement behind it.
     *
     * **Null, not zero, when there is nothing to read.** A labour line has no
     * cost of goods — an hour is produced at the moment it is sold — and
     * reporting ₹0 would claim a 100% margin on the workshop's most valuable
     * work. Null says "this question does not apply here", which is a different
     * answer and the true one.
     *
     * Requires the movement to be loaded; returns null rather than issuing a
     * query per line, so a listing cannot accidentally become N+1.
     */
    public function cost(): ?Money
    {
        if (! $this->relationLoaded('stockMovement') || $this->stockMovement === null) {
            return null;
        }

        return $this->stockMovement->valueMoney()->absolute();
    }

    /**
     * Taxable value minus cost — the margin on the goods, before tax.
     *
     * Tax is excluded because it is not the workshop's money: it is collected on
     * the department's behalf, and counting it as margin would flatter every
     * figure by the rate.
     */
    public function margin(): ?Money
    {
        $cost = $this->cost();

        return $cost === null ? null : $this->taxableMoney()->minus($cost);
    }

    /**
     * Sold for less than it cost.
     *
     * A warning and never a refusal: clearing old stock below cost is a real
     * decision, and so is a job quoted before the copper price moved. What the
     * workshop must not be allowed to do is *not notice*.
     */
    public function isBelowCost(): bool
    {
        return $this->margin()?->isNegative() ?? false;
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForVariant(Builder $query, int $variantId): Builder
    {
        return $query->where('variant_id', $variantId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStockLines(Builder $query): Builder
    {
        return $query->where('is_stock', true);
    }
}
