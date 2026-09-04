<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Exceptions\Accounting\LedgerImmutableException;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Accounting\PostingEngine;
use App\Support\Money;
use App\Support\Quantity;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the stock ledger: this variant, this much, at this cost.
 *
 * Every inventory number the product reports is a query over this table.
 * **There is no `qty_on_hand` column and no `avg_cost` column** — not on the
 * item, not on the variant, not anywhere — for exactly the reason there is no
 * balance column on an account: a stored aggregate agrees with its movements
 * right up until one is written without the other, and nobody notices until a
 * stock-take.
 *
 * Three rules hold without exception, and they are the same three the ledger
 * lives by:
 *
 *   1. Rows arrive only through {@see PostingEngine}, alongside the journal
 *      entries that value them, inside one database transaction.
 *   2. The quantity is signed and non-zero, and the value points the same way.
 *      Enforced by CHECK constraints as well as by the stock service.
 *   3. A row is never updated and never deleted — guarded below. A miscount is
 *      corrected by another movement, which is addition rather than erasure and
 *      leaves both the mistake and the correction on the record.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $transaction_id
 * @property int $item_id
 * @property int $variant_id
 * @property int|null $transaction_line_id
 * @property StockMovementType $type
 * @property string $quantity
 * @property string $unit_cost
 * @property string $value
 * @property Carbon $date
 * @property int $line_no
 * @property string|null $memo
 */
#[Fillable([
    'tenant_id', 'transaction_id', 'item_id', 'variant_id', 'transaction_line_id',
    'type', 'quantity', 'unit_cost', 'value', 'date', 'line_no', 'memo',
])]
class StockMovement extends Model
{
    use BelongsToTenant;

    /**
     * A movement is written once and never touched again, so a column claiming
     * to record its last modification would be a permanent lie.
     */
    public const UPDATED_AT = null;

    /**
     * Deliberately no HasFactory, exactly as {@see JournalEntry} has none. A
     * movement that did not come from the posting engine is stock value with no
     * accounting entry behind it — tests move stock by posting, like the
     * application does.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'value' => 'decimal:2',
            'date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $movement): void {
            throw LedgerImmutableException::updatingStock($movement->id);
        });

        static::deleting(function (self $movement): void {
            throw LedgerImmutableException::deletingStock($movement->id);
        });
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
     * @return BelongsTo<ItemVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'variant_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /* ---------------------------------------------------------------------
     | What caused this
     |-------------------------------------------------------------------- */

    /**
     * What to call this row on a stock card, when the movement type alone is not
     * enough to say what happened.
     *
     * A reversal is written as an `adjust` — see
     * {@see \App\Services\Accounting\Posting\StockChange::reversing()}, and that
     * is right, because a reversal genuinely is a correction and calling it an
     * `out` would make it indistinguishable in a stock report from a sale that
     * never happened. But `adjust` is also what a physical count writes, so a
     * reversed purchase and a stock-take correction came out of the ledger as
     * the same word with a signed number beside it, and there was no way to tell
     * which document had taken the stock away.
     *
     * The type stays as it is. What changes is that the row can now name the
     * document behind it, which is the traceability a purchase receipt already
     * had and its reversal did not.
     *
     * Null wherever the type already says everything — an `in` from a purchase,
     * an `out` from a sale, a hand-entered count — so every other stock card in
     * the application reads exactly as it did.
     */
    public function sourceLabel(): ?string
    {
        $transaction = $this->transaction;

        // Purchase documents only. A sale's reversal puts stock back and reads
        // correctly as an adjustment; widening this would relabel movements on
        // screens nobody has asked to change.
        if ($transaction === null
            || $transaction->reverses_id === null
            || ! $transaction->type->isPurchaseDocument()) {
            return null;
        }

        return $this->type === StockMovementType::Adjust
            ? sprintf('%s reversed', $transaction->type->label())
            : null;
    }

    /* ---------------------------------------------------------------------
     | Amounts
     |-------------------------------------------------------------------- */

    /**
     * The quantity, signed: positive for an arrival, negative for an issue.
     */
    public function quantityValue(): Quantity
    {
        return Quantity::of($this->quantity);
    }

    /**
     * What the Inventory account moved by. The authority on stock value.
     */
    public function valueMoney(): Money
    {
        return Money::of($this->value);
    }

    /**
     * The rate this movement was struck at. Document detail — never sum it.
     */
    public function unitCostMoney(): Money
    {
        return Money::of($this->unit_cost);
    }

    public function isIncrease(): bool
    {
        return $this->quantityValue()->isPositive();
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
     * @param  array<int, int>  $variantIds
     * @return Builder<self>
     */
    public function scopeForVariants(Builder $query, array $variantIds): Builder
    {
        // An empty list means "no variants", not "all variants" — the same
        // reading JournalEntry::scopeForAccounts() takes, and for the same
        // reason: the alternative hands the caller the whole stock ledger.
        return $variantIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('variant_id', $variantIds);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForItem(Builder $query, int $itemId): Builder
    {
        return $query->where('item_id', $itemId);
    }

    /**
     * Movements dated on or before a day — the "as at" of any stock position.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUpTo(Builder $query, DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('date', '<=', $date);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFrom(Builder $query, DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('date', '>=', $date);
    }

    /**
     * The order a stock card reads in: by day, then by the order the movements
     * were recorded.
     *
     * Note that this is *display* order, and it is not the order the weighted
     * average is computed in — see {@see \App\Services\Inventory\StockLedgerService}.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInStockOrder(Builder $query): Builder
    {
        return $query->orderBy('date')->orderBy('id');
    }
}
