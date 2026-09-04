<?php

namespace App\Services\Accounting\Posting;

use App\Enums\StockMovementType;
use App\Models\ItemVariant;
use App\Models\StockMovement;
use App\Support\Money;
use App\Support\Quantity;

/**
 * One intended stock movement, before it is written.
 *
 * The third member of the family {@see PostingLine} and {@see PaymentSplit}
 * belong to, and it is held for the same reason both of those are: a change that
 * cannot be constructed malformed cannot be posted malformed. A quantity and a
 * value that pointed opposite ways would be stock arriving while the Inventory
 * account fell, and there is no later point at which that is detectable as
 * anything other than a wrong balance.
 *
 * ## The value is not derived here, and that is deliberate
 *
 * An **arrival** carries its own cost: the supplier's invoice says what was
 * paid, so the value is quantity × the stated rate and this object can work it
 * out. An **issue** does not: it is worth whatever the position it comes out of
 * is worth, which only the stock ledger knows and only at the moment of posting.
 * So {@see issuing()} is handed a value that
 * {@see \App\Services\Inventory\StockLedgerService} computed under a lock, and
 * refuses to guess one.
 *
 * That asymmetry is the whole of weighted-average costing, stated once.
 *
 * Immutable, and carries no id: a StockChange describes a movement, it is not
 * one.
 */
final class StockChange
{
    private function __construct(
        public readonly int $itemId,
        public readonly int $variantId,
        public readonly StockMovementType $type,
        /** Signed: positive for an arrival, negative for an issue. */
        public readonly Quantity $quantity,
        /** The rate this movement was struck at. A magnitude, never negative. */
        public readonly Money $unitCost,
        /** What the Inventory account moves by. Signed, and the authority. */
        public readonly Money $value,
        public readonly ?string $memo = null,
        /**
         * The bill line this movement came from, where there is one.
         *
         * Carried as a *number* rather than an id because the line does not
         * exist yet — both are written inside the same database transaction, and
         * the engine pairs them by this after the lines are inserted. Null for a
         * stock adjustment, which moves quantities with no document behind them.
         */
        public readonly ?int $lineNo = null,
    ) {}

    /**
     * Stock arriving at a stated cost — a purchase, an opening balance, a
     * stock-take that found more than the books said.
     *
     * The quantity is taken as a magnitude and stored positive, because the type
     * already says which way this goes and a caller passing -3 to `arriving()`
     * has made a mistake worth surfacing rather than honouring.
     */
    public static function arriving(
        ItemVariant $variant,
        Quantity $quantity,
        Money $totalCost,
        StockMovementType $type = StockMovementType::In,
        ?string $memo = null,
        ?int $lineNo = null,
    ): self {
        $quantity = $quantity->absolute();

        return new self(
            itemId: (int) $variant->item_id,
            variantId: (int) $variant->id,
            type: $type,
            quantity: $quantity,
            // Derived from the total rather than the other way round: the
            // invoice states a total, and a rate re-multiplied by a quantity
            // does not always give it back. The rate is for the voucher.
            unitCost: $quantity->rateFrom($totalCost->absolute()),
            value: $totalCost->absolute(),
            memo: $memo,
            lineNo: $lineNo,
        );
    }

    /**
     * Stock leaving at a value the stock ledger has already computed — a sale,
     * the copper consumed by a rewind, a shortage found at a stock-take.
     *
     * Both the quantity and the value are stored negative whatever sign they
     * arrive with, for the same reason as above.
     */
    public static function issuing(
        ItemVariant $variant,
        Quantity $quantity,
        Money $value,
        StockMovementType $type = StockMovementType::Out,
        ?string $memo = null,
        ?int $lineNo = null,
    ): self {
        $quantity = $quantity->absolute();
        $value = $value->absolute();

        return new self(
            itemId: (int) $variant->item_id,
            variantId: (int) $variant->id,
            type: $type,
            quantity: $quantity->negated(),
            unitCost: $quantity->rateFrom($value),
            value: $value->negated(),
            memo: $memo,
            lineNo: $lineNo,
        );
    }

    /**
     * A movement mirrored, for a reversing transaction.
     *
     * Typed as an **adjustment** whichever way the original went, and
     * deliberately: a reversal is a correction, which is precisely what
     * `adjust` means, and it is the only case whose sign is not fixed by its
     * type. Calling the reversal of a purchase an `out` would make it
     * indistinguishable in a stock report from a sale that never happened.
     *
     * Nothing is re-valued. The quantity goes back at exactly the value it left
     * at, so the pair nets to zero in the position and in the Inventory account
     * alike — even though the weighted average has moved since, which is
     * precisely why re-valuing would leave a residue behind.
     */
    public static function reversing(StockMovement $movement, ?string $memo = null): self
    {
        return new self(
            itemId: (int) $movement->item_id,
            variantId: (int) $movement->variant_id,
            type: StockMovementType::Adjust,
            quantity: $movement->quantityValue()->negated(),
            unitCost: $movement->unitCostMoney(),
            value: $movement->valueMoney()->negated(),
            memo: $memo ?? sprintf('Reversal of %s', strtolower($movement->type->label())),
        );
    }

    /**
     * Stock coming back, at the value it went out at — M18.
     *
     * The third valuation rule, and it belongs with the other two. An arrival is
     * valued by the supplier's invoice; an issue by the position it comes out of;
     * a **return** by the movement it undoes. Never by today's average, for
     * exactly the reason {@see reversing()} does not re-value either: the average
     * has moved since, and a bearing put back at a price it never left at would
     * leave the Inventory account holding the difference for ever, against stock
     * that is physically on the shelf.
     *
     * `$value` is a magnitude the caller has worked out — the share of the
     * original movement that this quantity represents, net of anything returned
     * earlier. Only the return service can know that, because only it can see the
     * earlier returns; see `ReturnService::valueOfReturn()`.
     *
     * Typed as an ordinary in/out rather than an `adjust`, unlike a reversal.
     * A return is a real movement of real goods — the customer physically brought
     * the bearing back — where a reversal is a correction to a record. A stock
     * card that called them the same thing would make a fortnight of genuine
     * returns indistinguishable from a fortnight of clerical errors.
     */
    public static function returning(
        ItemVariant $variant,
        Quantity $quantity,
        Money $value,
        StockMovementType $type,
        ?string $memo = null,
        ?int $lineNo = null,
    ): self {
        return $type === StockMovementType::In
            ? self::arriving($variant, $quantity, $value, $type, $memo, $lineNo)
            : self::issuing($variant, $quantity, $value, $type, $memo, $lineNo);
    }

    /**
     * The same change, tied to a bill line.
     *
     * A wither rather than an argument on every constructor: the stock ledger
     * values a movement without knowing or caring what document asked for it,
     * and only the bill template knows the line number.
     */
    public function withLineNo(?int $lineNo): self
    {
        return new self(
            $this->itemId,
            $this->variantId,
            $this->type,
            $this->quantity,
            $this->unitCost,
            $this->value,
            $this->memo,
            $lineNo,
        );
    }

    public function isIncrease(): bool
    {
        return $this->quantity->isPositive();
    }

    /**
     * The total of a list of changes — what the Inventory account moves by, and
     * therefore the amount the posting template must put on the other side.
     *
     * Signed, and legitimately either way: a bill that both consumes copper and
     * takes back a returned bearing nets to whichever is larger.
     *
     * @param  array<int, self>  $changes
     */
    public static function totalValue(array $changes): Money
    {
        return Money::sum(array_map(fn (self $change) => $change->value, $changes));
    }

    /**
     * @param  array<int, self>  $changes
     * @return array<int, int>
     */
    public static function variantIds(array $changes): array
    {
        return array_values(array_unique(array_map(fn (self $change) => $change->variantId, $changes)));
    }

    /**
     * The storable shape, for a preview and for logging. Amounts and quantities
     * are decimal strings, never floats.
     *
     * Note that a *draft* does not store these: a draft holds the payload its
     * template composes from, and the stock is re-valued when it is finally
     * posted. Cost at the moment of drafting is not cost at the moment of sale,
     * and posting a fortnight-old valuation would put a number in COGS that was
     * true once.
     *
     * @return array{item_id: int, variant_id: int, type: string, quantity: string, unit_cost: string, value: string, memo: string|null}
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'variant_id' => $this->variantId,
            'type' => $this->type->value,
            'quantity' => $this->quantity->amount(),
            'unit_cost' => $this->unitCost->amount(),
            'value' => $this->value->amount(),
            'memo' => $this->memo,
        ];
    }
}
