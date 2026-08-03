<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * A quantity that is not a stock movement at all.
 *
 * The stock counterpart of {@see InvalidJournalException}, and it draws the same
 * line: this is for a movement that cannot be recorded, not for one that records
 * an awkward fact. Selling more than is on hand is an awkward fact — the goods
 * left the shop — and it produces a warning and a negative position, never one
 * of these. See {@see \App\Services\Inventory\StockLedgerService}.
 *
 * Each named constructor carries its own error code, so a client can explain the
 * specific problem rather than repeating a generic "invalid movement".
 */
class InvalidStockMovementException extends ApiException
{
    /**
     * A service, or something the workshop has said it does not inventory.
     *
     * An hour of labour is produced at the moment it is sold; there is nothing
     * to hold and nothing to value. A part bought to order is a choice rather
     * than an impossibility, but the answer is the same — the workshop said not
     * to count it, so counting it would contradict them.
     */
    public static function notStocked(string $variant, string $typeLabel): self
    {
        return new self(
            message: sprintf(
                '%s is a %s that this workshop does not hold in stock, so no quantity of it can move. '.
                'Turn stock tracking on for the item if that has changed.',
                $variant,
                strtolower($typeLabel),
            ),
            status: 422,
            errorCode: 'STOCK_NOT_TRACKED',
            details: ['field' => 'variant_id', 'variant' => $variant],
        );
    }

    /**
     * A movement of nothing would sit in the history claiming something
     * happened, and value at zero.
     */
    public static function zeroQuantity(string $variant): self
    {
        return new self(
            message: "The quantity for {$variant} must be more than zero.",
            status: 422,
            errorCode: 'STOCK_QUANTITY_INVALID',
            details: ['field' => 'quantity', 'variant' => $variant],
        );
    }

    /**
     * Half a bearing is not a quantity. Refused rather than rounded, because
     * rounding it would post a number nobody typed — see
     * {@see \App\Enums\UnitOfMeasure::isFractional()}.
     */
    public static function fractionalUnit(string $variant, string $quantity, string $unitLabel): self
    {
        return new self(
            message: sprintf(
                '%s is counted in whole %ss, so %s of it is not a quantity anyone can pick or count.',
                $variant,
                strtolower($unitLabel),
                $quantity,
            ),
            status: 422,
            errorCode: 'STOCK_QUANTITY_FRACTIONAL',
            details: ['field' => 'quantity', 'variant' => $variant, 'unit' => $unitLabel],
        );
    }

    /**
     * A negative cost is not a discount — it is a number nobody meant to type,
     * and it would flow straight into the Inventory account.
     */
    public static function negativeCost(string $variant): self
    {
        return new self(
            message: "The cost for {$variant} cannot be negative.",
            status: 422,
            errorCode: 'STOCK_COST_INVALID',
            details: ['field' => 'unit_cost', 'variant' => $variant],
        );
    }

    /**
     * A variant whose family is missing — the attribute schema, the unit and the
     * tax code all live on the item, so there is nothing here to validate
     * against.
     */
    public static function unknownItem(int $variantId): self
    {
        return new self(
            message: 'This variant has no item behind it, so there is no unit to record a quantity in.',
            status: 422,
            errorCode: 'STOCK_ITEM_UNKNOWN',
            details: ['field' => 'variant_id', 'variant_id' => $variantId],
        );
    }

    /**
     * A movement naming a variant that is not this workshop's.
     *
     * The tenant scope does the isolation half on its own — another workshop's
     * variant id simply does not resolve — so this is what the caller sees.
     */
    public static function unknownVariant(int $variantId): self
    {
        return new self(
            message: 'This transaction names an item variant that does not exist in this workshop.',
            status: 422,
            errorCode: 'STOCK_VARIANT_UNKNOWN',
            details: ['field' => 'variant_id', 'variant_id' => $variantId],
        );
    }

    /**
     * A stock adjustment with nothing to adjust.
     */
    public static function noAdjustments(): self
    {
        return new self(
            message: 'A stock adjustment needs at least one line saying what the count actually found.',
            status: 422,
            errorCode: 'STOCK_ADJUSTMENT_EMPTY',
            details: ['field' => 'adjustments'],
        );
    }

    /**
     * An adjustment where nothing is worth anything.
     *
     * Refused rather than posted, because a transaction with no ledger lines is
     * not a transaction — and letting quantities move with no accounting trace
     * is precisely the drift between shelf and books that this module exists to
     * make impossible. The message names the fix, which is usually that somebody
     * has to say what the found stock is worth.
     */
    public static function valuelessAdjustment(): self
    {
        return new self(
            message: 'None of these adjustments changes what the stock is worth, so there would be nothing to '.
                'record in the books. Give the found stock a cost — or, if the quantity came in on a bill, '.
                'correct the bill instead.',
            status: 422,
            errorCode: 'STOCK_ADJUSTMENT_VALUELESS',
            details: ['field' => 'adjustments'],
        );
    }

    /**
     * An archived variant is one the workshop has stopped dealing in. Its
     * history stays readable; nothing new moves through it.
     */
    public static function archivedVariant(int $variantId, string $variant): self
    {
        return new self(
            message: "\"{$variant}\" is archived, so no new stock can move through it. ".
                'Restore the variant or choose another.',
            status: 422,
            errorCode: 'STOCK_VARIANT_ARCHIVED',
            details: ['field' => 'variant_id', 'variant_id' => $variantId],
        );
    }
}
