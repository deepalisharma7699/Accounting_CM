<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\Accounting\InsufficientStockException;
use App\Exceptions\Accounting\InvalidStockMovementException;
use App\Models\ItemVariant;
use App\Repositories\Contracts\ItemVariantRepositoryInterface;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Accounting\Posting\StockChange;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What is on hand, what it is worth, and what it costs to take some out.
 *
 * The counterpart of {@see \App\Services\Accounting\LedgerService}: every number
 * it reports is a sum over `stock_movements`, and none of them is stored. The
 * roadmap's first invariant — that quantity on hand and average cost change only
 * through a movement — holds by construction here, because there is no column
 * for either to be edited in.
 *
 * ## Weighted average cost, in one paragraph
 *
 * Buy 10 kg of copper at ₹700 and the position is 10 kg worth ₹7,000. Buy 10 kg
 * more at ₹800 and it is 20 kg worth ₹15,000 — ₹750/kg, which is neither price
 * paid and is the point. Issue 3 kg and the position loses its *share* of the
 * value, ₹2,250, leaving 17 kg worth ₹12,750 — still ₹750/kg, because issuing
 * stock cannot make the remainder dearer or cheaper than it was a second
 * earlier. Only an arrival changes the average, and it does so by arithmetic
 * nobody has to perform: the average is the value divided by the quantity, and
 * both are sums.
 *
 * ## Why an issue takes a *share* rather than quantity × average
 *
 * Because the average is a rounded number. 3 kg out of a 10 kg position worth
 * ₹7,501 is ₹2,250.30 by share and ₹2,250.30 by 3 × ₹750.10 — but over a long
 * run of odd quantities the two diverge, and the difference accumulates in the
 * Inventory account as stock value with no stock behind it. Taking the share
 * means an issue of *everything* takes exactly the whole value, so a variant
 * that sells out leaves the Inventory account at exactly zero.
 *
 * ## Negative stock is refused by default, and the workshop may allow it
 *
 * M8 decided the opposite — allow it, surface it, never hide it — on the
 * grounds that a fitter records Tuesday's sale before Friday's supplier invoice
 * arrives, and blocking Tuesday's sale does not produce the bearing; it produces
 * a workshop that stops recording sales.
 *
 * That is still true of the workshop it describes. It is not true of the one the
 * brief describes, which wants to be told *"Only 5 PCS available in stock."*
 * before it promises a customer a sixth — because a counter that will cheerfully
 * sell what is not there is a counter whose stock figures nobody trusts.
 *
 * Both workshops are real, so the answer is a setting rather than a new
 * absolute: `tenants.allow_negative_stock`, off by default, and the refusal
 * message says outright that it exists. See {@see assertCanIssue()} and M17's
 * decision D6.
 *
 * Two things the refusal deliberately does *not* cover. A **stock adjustment**
 * is exempt: it is the workshop asserting what is physically on the shelf, which
 * is the authority the books answer to and the only tool for repairing a
 * position that has already gone negative — refusing it would leave a workshop
 * stuck with a wrong figure and no way to correct it. And a **reversal** is
 * exempt for the same reason it may touch an archived variant: a known error
 * must never become permanent because the shelf has moved since.
 *
 * Where negative stock does occur — under the permissive setting, or through an
 * adjustment — it must never silently invent a cost. An issue out of an empty
 * position is valued at the last rate the workshop actually paid (see
 * {@see issueCostFor()}) and the position reports
 * {@see StockPosition::isNegative()} so the stock screen shows it as the data
 * problem it is.
 */
class StockLedgerService
{
    public function __construct(
        private readonly StockMovementRepositoryInterface $movements,
        private readonly ItemVariantRepositoryInterface $variants,
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $context,
    ) {}

    /* ---------------------------------------------------------------------
     | Positions
     |-------------------------------------------------------------------- */

    /**
     * What is on hand of one variant, optionally as at a date.
     */
    public function positionFor(ItemVariant $variant, ?string $to = null): StockPosition
    {
        $row = $this->movements->positionFor((int) $variant->id, $to);

        return StockPosition::of(
            (int) $variant->id,
            Quantity::of($row['quantity']),
            Money::of($row['value']),
            Quantity::ofNullable($variant->reorder_level),
        );
    }

    /**
     * Positions for many variants at once, keyed by variant id.
     *
     * One query for the whole set. A variant that has never moved is present
     * with a zero position rather than absent, because "nothing in stock" is an
     * answer and a missing key is a bug waiting to be read as one.
     *
     * @param  Collection<int, ItemVariant>|array<int, ItemVariant>  $variants
     * @return array<int, StockPosition>
     */
    public function positionsFor(Collection|array $variants, ?string $to = null): array
    {
        $variants = $variants instanceof Collection ? $variants : new Collection($variants);
        $ids = $variants->pluck('id')->map(fn ($id) => (int) $id)->all();

        $rows = $this->movements->positionsFor($ids, $to)->keyBy('variant_id');

        $positions = [];

        foreach ($variants as $variant) {
            $id = (int) $variant->id;
            $row = $rows->get($id);
            $reorder = Quantity::ofNullable($variant->reorder_level);

            $positions[$id] = $row === null
                ? StockPosition::empty($id, $reorder)
                : StockPosition::of($id, Quantity::of($row['quantity']), Money::of($row['value']), $reorder);
        }

        return $positions;
    }

    /* ---------------------------------------------------------------------
     | Valuation
     |-------------------------------------------------------------------- */

    /**
     * What it costs to take a quantity out of stock, right now.
     *
     * Three cases, and the third is the interesting one:
     *
     *   * **enough on hand** — the issue takes its proportional share of the
     *     position's value;
     *   * **exactly all of it** — the issue takes the whole remaining value, so
     *     the position lands on precisely zero rather than a few paise short;
     *   * **more than there is** — what exists is valued at what it is worth,
     *     and the shortfall at the last rate the workshop actually paid. Never
     *     at zero: a bearing sold before its invoice was entered still cost
     *     something, and pricing it at nothing would report a margin of 100% on
     *     a sale that made the usual amount.
     */
    public function issueCostFor(ItemVariant $variant, Quantity $quantity, ?string $to = null): Money
    {
        $wanted = $quantity->absolute();
        $position = $this->positionFor($variant, $to);

        if (! $position->hasStock()) {
            return $wanted->costAt($this->fallbackRateFor($variant));
        }

        if ($wanted->isAtMost($position->quantity)) {
            // Equal quantities take the whole value: shareOf() with an equal
            // numerator and denominator returns it exactly, with no rounding.
            return $wanted->shareOf($position->value, $position->quantity);
        }

        $shortfall = $wanted->minus($position->quantity);

        return $position->value->plus($shortfall->costAt($position->averageCost()));
    }

    /**
     * The rate to fall back on when there is nothing on hand to average.
     *
     * The last arrival's rate, because that is the last thing the workshop
     * actually knows about what this costs. Zero only when the variant has never
     * been bought at all — which is a genuine "we have no idea", and a warning
     * on the bill rather than a number pretending to be one.
     */
    public function fallbackRateFor(ItemVariant $variant): Money
    {
        $rate = $this->movements->lastArrivalCostFor((int) $variant->id);

        return $rate === null ? Money::zero() : Money::of($rate);
    }

    /**
     * The cost a bill line should be valued at for margin purposes, whether or
     * not the variant currently has stock.
     */
    public function unitCostFor(ItemVariant $variant): Money
    {
        $position = $this->positionFor($variant);

        return $position->hasStock()
            ? $position->averageCost()
            : $this->fallbackRateFor($variant);
    }

    /* ---------------------------------------------------------------------
     | Building movements
     |-------------------------------------------------------------------- */

    /**
     * Stock arriving at a stated cost.
     *
     * @throws InvalidStockMovementException
     */
    public function receipt(
        ItemVariant $variant,
        Quantity $quantity,
        Money $totalCost,
        StockMovementType $type = StockMovementType::In,
        ?string $memo = null,
    ): StockChange {
        $this->assertMovable($variant, $quantity);

        if ($totalCost->isNegative()) {
            throw InvalidStockMovementException::negativeCost($this->describe($variant));
        }

        return StockChange::arriving($variant, $quantity, $totalCost, $type, $memo);
    }

    /**
     * Stock leaving, valued at what the position it comes out of is worth.
     *
     * Takes a write lock on the variant when it is called inside a database
     * transaction — which is exactly when the answer is about to be written, and
     * exactly when two simultaneous sales of the last motor would otherwise both
     * value at the pre-sale average and leave the Inventory account describing
     * stock that was sold twice. A preview is outside a transaction by
     * construction and takes no lock, which is right: a preview is advisory.
     *
     * @throws InvalidStockMovementException
     */
    public function issue(
        ItemVariant $variant,
        Quantity $quantity,
        StockMovementType $type = StockMovementType::Out,
        ?string $memo = null,
    ): StockChange {
        $this->assertMovable($variant, $quantity);

        $this->lockIfWriting([(int) $variant->id]);

        // Note what is *not* checked here: whether the shelf holds enough. That
        // refusal belongs to the posting, not to building the movement — a draft
        // and a preview both compose issues out of stock nobody has bought yet,
        // and refusing them would mean an unfinished bill could not be parked
        // until the supplier's invoice arrived. See
        // {@see \App\Services\Accounting\PostingEngine::assertStockAvailable()},
        // which runs once per variant with the lock above already held.
        return StockChange::issuing($variant, $quantity, $this->issueCostFor($variant, $quantity), $type, $memo);
    }

    /**
     * A stock-take correction, in whichever direction the shelf disagrees.
     *
     * An increase is valued at a stated rate — somebody has to say what the
     * found stock is worth, and the honest default is what it last cost. A
     * decrease is valued at what the books were carrying it at, which is not the
     * counter's to decide.
     *
     * @param  Quantity  $delta  Signed: the difference the count found.
     *
     * @throws InvalidStockMovementException
     */
    public function adjustment(ItemVariant $variant, Quantity $delta, ?Money $unitCost = null, ?string $memo = null): StockChange
    {
        $this->assertMovable($variant, $delta);

        if ($delta->isZero()) {
            throw InvalidStockMovementException::zeroQuantity($this->describe($variant));
        }

        if ($delta->isNegative()) {
            $this->lockIfWriting([(int) $variant->id]);

            return StockChange::issuing(
                $variant,
                $delta,
                $this->issueCostFor($variant, $delta),
                StockMovementType::Adjust,
                $memo,
            );
        }

        $rate = $unitCost ?? $this->unitCostFor($variant);

        if ($rate->isNegative()) {
            throw InvalidStockMovementException::negativeCost($this->describe($variant));
        }

        return StockChange::arriving($variant, $delta, $delta->costAt($rate), StockMovementType::Adjust, $memo);
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    /**
     * What must be true of any quantity before it reaches the stock ledger.
     *
     * Here rather than in a form request, exactly as M7 put the attribute schema
     * in the service: M9's bills, M11's importer and M15's capture agent all
     * move stock without passing through a controller, and a rule enforced in
     * one of them says nothing about the other three.
     *
     * @throws InvalidStockMovementException
     */
    public function assertMovable(ItemVariant $variant, Quantity $quantity): void
    {
        $item = $variant->item;

        if ($item === null) {
            throw InvalidStockMovementException::unknownItem((int) $variant->id);
        }

        // Capability first, then the workshop's choice — the pairing M7 put on
        // Item::tracksStock() precisely so nothing has to remember both halves.
        if (! $item->tracksStock()) {
            throw InvalidStockMovementException::notStocked($this->describe($variant), $item->categoryLabel());
        }

        if ($quantity->isZero()) {
            throw InvalidStockMovementException::zeroQuantity($this->describe($variant));
        }

        // 2.5 kg of copper is ordinary; 2.5 bearings is a mistake somebody
        // should be told about before it reaches the stock ledger.
        if (! $quantity->fitsUnit($item->base_uom)) {
            throw InvalidStockMovementException::fractionalUnit(
                $this->describe($variant),
                $quantity->trimmed(),
                $item->base_uom->label(),
            );
        }
    }

    /**
     * Refuse an issue that would take a variant below zero — M17, decision D6.
     *
     * The brief's own words, in the brief's own units: *"Only 5 PCS available in
     * stock."* Somebody at a counter needs to know what they can promise, and
     * "insufficient stock" tells them nothing they can act on.
     *
     * Called by {@see \App\Services\Accounting\PostingEngine::assertStockAvailable()}
     * and nowhere else — because *posting* is the moment the rule applies. A
     * draft and a preview both compose issues out of stock the workshop has not
     * bought yet, quite legitimately: parking an unfinished bill until the
     * supplier's invoice arrives is exactly what a draft is for.
     *
     * Silent where the workshop has said it bills ahead of its paperwork. That is
     * the escape hatch M8's original reasoning earned, and the refusal message
     * names it — a rule with a way through that nobody can find is a rule people
     * work around by not recording sales.
     *
     * @throws InsufficientStockException
     */
    public function assertCanIssue(ItemVariant $variant, Quantity $quantity): void
    {
        if ($this->allowsNegativeStock() || ! $this->wouldGoNegative($variant, $quantity)) {
            return;
        }

        $available = $this->positionFor($variant)->quantity;

        throw InsufficientStockException::forVariant(
            $this->describe($variant),
            // Trimmed, so a workshop that deals in whole bearings is told "5"
            // rather than "5.000" — three decimal places on a countable thing
            // reads as a system talking to itself.
            $available->trimmed(),
            $variant->item?->base_uom->symbol() ?? '',
            $quantity->absolute()->trimmed(),
        );
    }

    /**
     * Whether this workshop has chosen to permit issuing what it does not have.
     *
     * False where the tenant cannot be resolved at all, which is the safe answer:
     * an unknown setting should not be read as permission.
     */
    public function allowsNegativeStock(): bool
    {
        $tenantId = $this->context->current();

        if ($tenantId === null) {
            return false;
        }

        return (bool) $this->tenants->findById($tenantId)?->allow_negative_stock;
    }

    /**
     * Whether taking this much out would leave the position below zero.
     *
     * The question behind both the refusal above and the warning a posted bill
     * still carries — because under the permissive setting the bill posts, and
     * somebody has to be told.
     */
    public function wouldGoNegative(ItemVariant $variant, Quantity $quantity): bool
    {
        return $this->positionFor($variant)->quantity
            ->minus($quantity->absolute())
            ->isNegative();
    }

    /**
     * Whether anything has ever moved for this variant — what turns deleting one
     * into a refusal, the same way a posted entry does for a party.
     */
    public function hasMovements(ItemVariant $variant): bool
    {
        return $this->movements->countForVariant((int) $variant->id) > 0;
    }

    public function movementCountForItem(int $itemId): int
    {
        return $this->movements->countForItem($itemId);
    }

    /**
     * @param  array<int, int>  $variantIds
     * @return array<int, int>
     */
    public function movedVariantIds(array $variantIds): array
    {
        return $this->movements->movedVariantIds($variantIds);
    }

    /* ---------------------------------------------------------------------
     | Reporting
     |-------------------------------------------------------------------- */

    /**
     * One variant's stock card: every movement, with the position after each.
     *
     * The running position opens at whatever preceded the first row on this
     * page, so page two does not start again from zero.
     *
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return array{movements: \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\StockMovement>, rows: array<int, array<string, mixed>>, opening: StockPosition, closing: StockPosition}
     */
    public function cardFor(ItemVariant $variant, array $filters = [], int $perPage = 50): array
    {
        $page = $this->movements->forVariant((int) $variant->id, $filters, $perPage);
        $reorder = Quantity::ofNullable($variant->reorder_level);

        $first = $page->items()[0] ?? null;

        $before = $first === null
            ? ['quantity' => '0', 'value' => '0']
            : $this->movements->positionBefore(
                (int) $variant->id,
                $first->date->toDateString(),
                (int) $first->id,
            );

        $runningQty = Quantity::of($before['quantity']);
        $runningValue = Money::of($before['value']);

        $opening = StockPosition::of((int) $variant->id, $runningQty, $runningValue, $reorder);

        $rows = [];

        foreach ($page->items() as $movement) {
            $runningQty = $runningQty->plus($movement->quantityValue());
            $runningValue = $runningValue->plus($movement->valueMoney());

            $after = StockPosition::of((int) $variant->id, $runningQty, $runningValue, $reorder);

            $rows[] = [
                'movement' => $movement,
                'balance' => $after,
            ];
        }

        return [
            'movements' => $page,
            'rows' => $rows,
            'opening' => $opening,
            'closing' => StockPosition::of((int) $variant->id, $runningQty, $runningValue, $reorder),
        ];
    }

    /**
     * The workshop's whole stock value, for reconciling against the Inventory
     * account.
     *
     * @return array{quantity: Quantity, value: Money}
     */
    public function totals(?string $to = null): array
    {
        $row = $this->movements->totals($to);

        return [
            'quantity' => Quantity::of($row['quantity']),
            'value' => Money::of($row['value']),
        ];
    }

    /**
     * The stock screen: every inventoried variant with its position, filtered
     * and sorted on figures that are not columns.
     *
     * Sorted and paged in PHP rather than in SQL, and deliberately. "What is
     * running out" and "what has gone negative" are questions about a sum over
     * `stock_movements`, so a page the database chose would be a page chosen
     * before the question was asked — the first fifty variants alphabetically,
     * of which none may be low. One query fetches the variants and one more
     * fetches every position behind them, however many rows there are.
     *
     * @param  array{search?: string|null, item_id?: int|null, type?: string|null, is_active?: bool|null, status?: string|null, sort?: string|null, direction?: string|null}  $filters
     * @return array{rows: array<int, array{variant: ItemVariant, position: StockPosition}>, totals: array{quantity: Quantity, value: Money, variants: int, low: int, negative: int, out_of_stock: int}}
     */
    public function report(array $filters = []): array
    {
        $variants = $this->variants->stocked($filters);
        $positions = $this->positionsFor($variants);

        $rows = [];
        $low = $negative = $out = 0;
        $quantity = Quantity::zero();
        $value = Money::zero();

        foreach ($variants as $variant) {
            $position = $positions[(int) $variant->id];

            // The counts are over everything that matched the *text* filters,
            // not over what survives the status filter below — "3 low" has to
            // stay 3 when the user clicks it, or the badge contradicts the
            // screen it opened.
            $quantity = $quantity->plus($position->quantity);
            $value = $value->plus($position->value);

            if ($position->isNegative()) {
                $negative++;
            } elseif ($position->isEmpty()) {
                $out++;
            }

            if ($position->isLow()) {
                $low++;
            }

            if (! $this->matchesStatus($position, $filters['status'] ?? null)) {
                continue;
            }

            $rows[] = ['variant' => $variant, 'position' => $position];
        }

        $rows = $this->sortRows($rows, $filters['sort'] ?? null, $filters['direction'] ?? null);

        return [
            'rows' => $rows,
            'totals' => [
                'quantity' => $quantity,
                'value' => $value,
                'variants' => $variants->count(),
                'low' => $low,
                'negative' => $negative,
                'out_of_stock' => $out,
            ],
        ];
    }

    private function matchesStatus(StockPosition $position, ?string $status): bool
    {
        return match ($status) {
            'low' => $position->isLow(),
            'negative' => $position->isNegative(),
            'out' => $position->isEmpty(),
            'in_stock' => $position->hasStock(),
            default => true,
        };
    }

    /**
     * @param  array<int, array{variant: ItemVariant, position: StockPosition}>  $rows
     * @return array<int, array{variant: ItemVariant, position: StockPosition}>
     */
    private function sortRows(array $rows, ?string $sort, ?string $direction): array
    {
        // Name order is what the repository already returned — item, then label
        // — so there is nothing to do for the default.
        if ($sort === null || $sort === 'name') {
            return $direction === 'desc' ? array_reverse($rows) : $rows;
        }

        $descending = $direction !== 'asc';

        usort($rows, function (array $a, array $b) use ($sort) {
            return match ($sort) {
                'quantity' => $a['position']->quantity->compareTo($b['position']->quantity),
                'value' => $a['position']->value->compareTo($b['position']->value),
                'cost' => $a['position']->averageCost()->compareTo($b['position']->averageCost()),
                default => 0,
            };
        });

        return $descending ? array_reverse($rows) : $rows;
    }

    /* ---------------------------------------------------------------------
     | Internals
     |-------------------------------------------------------------------- */

    /**
     * Lock the variants a write is about to touch, and only when there is a
     * write: `lockForUpdate` outside a transaction is released immediately and
     * would be a lie about the guarantee.
     *
     * @param  array<int, int>  $variantIds
     */
    private function lockIfWriting(array $variantIds): void
    {
        if (DB::transactionLevel() > 0) {
            $this->movements->lockForValuation($variantIds);
        }
    }

    private function describe(ItemVariant $variant): string
    {
        return $variant->displayLabel();
    }
}
