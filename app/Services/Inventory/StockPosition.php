<?php

namespace App\Services\Inventory;

use App\Support\Money;
use App\Support\Quantity;

/**
 * What is on hand of one variant, and what it is worth.
 *
 * Both numbers are sums over `stock_movements` and neither is stored anywhere.
 * The weighted average cost is not a third number: it is the value divided by
 * the quantity, computed once here so that no caller can arrive at a slightly
 * different one by rounding in its own order.
 *
 * A position is legitimately **negative**, and this class says so rather than
 * clamping. The goods left the shop; refusing to represent that would not put
 * them back. See {@see StockLedgerService} on why negative stock is warned about
 * rather than blocked.
 */
final class StockPosition
{
    private function __construct(
        public readonly int $variantId,
        public readonly Quantity $quantity,
        public readonly Money $value,
        /** The reorder level the workshop set, where they set one. */
        public readonly ?Quantity $reorderLevel = null,
    ) {}

    public static function of(
        int $variantId,
        Quantity $quantity,
        Money $value,
        ?Quantity $reorderLevel = null,
    ): self {
        return new self($variantId, $quantity, $value, $reorderLevel);
    }

    public static function empty(int $variantId, ?Quantity $reorderLevel = null): self
    {
        return new self($variantId, Quantity::zero(), Money::zero(), $reorderLevel);
    }

    /**
     * The weighted average cost: value ÷ quantity.
     *
     * Zero when there is nothing on hand, which is honest rather than useful —
     * a variant with no stock has no average, and the last price paid is a
     * different question with a different answer. See
     * {@see StockLedgerService::issueCostFor()}, which asks that question when it
     * has to value an issue out of an empty position.
     */
    public function averageCost(): Money
    {
        return $this->quantity->isPositive()
            ? $this->quantity->rateFrom($this->value)
            : Money::zero();
    }

    public function hasStock(): bool
    {
        return $this->quantity->isPositive();
    }

    public function isEmpty(): bool
    {
        return $this->quantity->isZero();
    }

    /**
     * More has gone out than ever came in.
     *
     * Always a data problem — either a sale recorded before its purchase, or a
     * missed receipt — and never a normal state, which is why it is surfaced on
     * its own rather than folded into "low stock".
     */
    public function isNegative(): bool
    {
        return $this->quantity->isNegative();
    }

    /**
     * At or below the level the workshop said to reorder at.
     *
     * Zero counts as low when a level is set — running out is the case the
     * reminder exists for. A variant with no level set is never low, because
     * nobody has said what low means for it.
     */
    public function isLow(): bool
    {
        return $this->reorderLevel !== null
            && ! $this->isNegative()
            && $this->quantity->isAtMost($this->reorderLevel);
    }

    /**
     * @return array{quantity: string, value: string, average_cost: string, has_stock: bool, is_low: bool, is_negative: bool, reorder_level: string|null}
     */
    public function toArray(): array
    {
        return [
            'quantity' => $this->quantity->amount(),
            'value' => $this->value->amount(),
            'average_cost' => $this->averageCost()->amount(),
            'has_stock' => $this->hasStock(),
            'is_low' => $this->isLow(),
            'is_negative' => $this->isNegative(),
            'reorder_level' => $this->reorderLevel?->amount(),
        ];
    }
}
