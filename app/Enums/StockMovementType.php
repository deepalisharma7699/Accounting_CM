<?php

namespace App\Enums;

use App\Models\StockMovement;

/**
 * Why a quantity moved.
 *
 * The *direction* is the sign of the quantity, not the type — see
 * {@see \App\Support\Quantity}. This says what happened, which is a different
 * question and the one a stock ledger has to be able to answer months later:
 * "three went out" is not a record, "three went out on invoice 412" is.
 *
 * Only four cases, and they are exhaustive on purpose. Stock either arrives,
 * leaves, is corrected against a physical count, or is declared at go-live.
 * Anything a workshop wants to call something else — damage, a sample, a rework
 * — is one of those four with a note on it, and adding a case per business
 * reason would mean a migration every time the trade invents a word.
 *
 * @see StockMovement
 */
enum StockMovementType: string
{
    /**
     * Stock arrived: a purchase, or a return from a customer.
     *
     * The only kind of movement that changes the weighted average cost, because
     * it is the only kind that brings a *new* cost into the position.
     */
    case In = 'in';

    /**
     * Stock left: a sale, or the copper consumed by a rewind.
     *
     * Valued at the average cost of what is on hand at that moment, and it does
     * **not** change that average — issuing stock cannot make the remainder
     * cheaper or dearer than it was a second earlier.
     */
    case Out = 'out';

    /**
     * A correction against a physical count, in either direction.
     *
     * The one type whose sign is not implied, which is why the sign lives on the
     * quantity: a stock-take that finds two fewer bearings and one more motor is
     * two adjustments, and they point opposite ways.
     */
    case Adjust = 'adjust';

    /**
     * What was on the shelf on the day the books opened — M11.
     *
     * Kept separate from `in` even though both increase stock, because it is not
     * a purchase: nothing was bought, no supplier is owed and no GST was paid.
     * Its other side is Opening Balance Equity rather than a payable, and a
     * report that could not tell the two apart would show a workshop's first
     * month as an enormous purchase.
     */
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Stock in',
            self::Out => 'Stock out',
            self::Adjust => 'Adjustment',
            self::Opening => 'Opening stock',
        };
    }

    /**
     * The sign the quantity must carry, or null where the type allows either.
     *
     * Enforced by the stock service and again by a CHECK constraint, so an `in`
     * of minus three — which would read as an arrival and behave as an issue —
     * cannot exist.
     */
    public function requiredSign(): ?int
    {
        return match ($this) {
            self::In, self::Opening => 1,
            self::Out => -1,
            self::Adjust => null,
        };
    }

    /**
     * Whether the caller states the cost, or the stock ledger works it out.
     *
     * An arrival carries a cost with it — the invoice says what was paid. An
     * issue does not: it is valued at what the position is already worth, and a
     * caller allowed to state that number could sell stock out of the books at a
     * price it was never bought at.
     */
    public function carriesItsOwnCost(): bool
    {
        return match ($this) {
            self::In, self::Opening => true,
            self::Out => false,
            // Only when it adds stock. A stock-take that finds *fewer* bearings
            // writes off what those bearings were carried at, which is the
            // ledger's number and not the counter's.
            self::Adjust => true,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
