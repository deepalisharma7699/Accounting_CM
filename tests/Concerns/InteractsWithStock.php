<?php

namespace Tests\Concerns;

use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\PostingEngine;
use App\Services\Inventory\StockLedgerService;
use Closure;

/**
 * Helpers for anything that moves a quantity.
 *
 * The important one is {@see assertStockAgreesWithInventoryAccount()}. M8's
 * whole premise is that the shelf and the Inventory account are the same number
 * arrived at two ways, so — exactly as {@see InteractsWithLedger} does for the
 * trial balance — it is one assertion rather than something each test
 * re-derives.
 *
 * Note that there is no `StockMovement::factory()` to reach for, deliberately:
 * a movement that did not come from the posting engine is stock value with no
 * accounting entry behind it. Stock arrives here the way it arrives in the
 * application, by posting a transaction.
 *
 * Use alongside {@see InteractsWithTenancy} and {@see InteractsWithLedger}.
 */
trait InteractsWithStock
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    abstract protected function actingForTenant(Tenant|int|null $tenant, Closure $callback): mixed;

    abstract protected function balanceOf(Tenant $tenant, SystemAccount $key): string;

    abstract protected function engine(): PostingEngine;

    protected function stock(): StockLedgerService
    {
        return app(StockLedgerService::class);
    }

    /* ---------------------------------------------------------------------
     | Catalogue
     |-------------------------------------------------------------------- */

    /**
     * A stocked variant of a given category, with its item loaded — the starting
     * point of almost every test here.
     *
     * The category is named by code, and the four seeded codes are the old
     * `ItemType` values verbatim — so `variantFor($tenant, 'motor')` means what
     * `variantFor($tenant, 'motor')` meant.
     */
    protected function variantFor(
        Tenant $tenant,
        string $category = 'part',
        ?string $reorderLevel = null,
        ?string $sellPrice = null,
    ): ItemVariant {
        return $this->actingForTenant($tenant, function () use ($category, $reorderLevel, $sellPrice) {
            $item = Item::factory()->ofCategory($category)->create();

            $variant = ItemVariant::factory()->for($item)->create([
                'label' => $item->name,
                'reorder_level' => $reorderLevel,
                'sell_price' => $sellPrice,
            ]);

            return $variant->setRelation('item', $item);
        });
    }

    /**
     * A service item — the one type that can never hold stock.
     */
    protected function serviceVariantFor(Tenant $tenant, ?string $sellPrice = '800.00'): ItemVariant
    {
        return $this->variantFor($tenant, 'service', sellPrice: $sellPrice);
    }

    /* ---------------------------------------------------------------------
     | Moving stock
     |-------------------------------------------------------------------- */

    /**
     * Post a stock adjustment — template G.
     *
     * The split is given as `[[$variant, '10', '700.00']]`: the variant, the
     * signed difference, and the rate for anything *found*. A shortage takes the
     * rate the books were carrying, so the third element is left out.
     *
     * @param  array<int, array{0: ItemVariant, 1: string, 2?: string|null, 3?: string|null}>  $rows
     */
    protected function adjustStock(
        Tenant $tenant,
        array $rows,
        ?string $date = null,
        ?string $notes = null,
        ?User $actor = null,
        bool $post = true,
    ): Transaction {
        return $this->actingForTenant($tenant, function () use ($rows, $date, $notes, $actor, $post) {
            $input = [
                'date' => $date ?? now()->toDateString(),
                'notes' => $notes,
                'adjustments' => array_map(fn (array $row) => [
                    'variant_id' => $row[0]->id,
                    'quantity' => $row[1],
                    'unit_cost' => $row[2] ?? null,
                    'memo' => $row[3] ?? null,
                ], $rows),
            ];

            return $post
                ? $this->engine()->postComposed(TransactionType::StockAdjustment, $input, $actor)
                : $this->engine()->draft(
                    $this->engine()->compose(TransactionType::StockAdjustment, $input),
                    $actor,
                );
        });
    }

    /**
     * Stock arriving at a stated rate — the shape "10 kg at ₹700" takes before
     * M9's purchase bill exists.
     */
    protected function receiveStock(
        Tenant $tenant,
        ItemVariant $variant,
        string $quantity,
        string $unitCost,
        ?string $date = null,
    ): Transaction {
        return $this->adjustStock($tenant, [[$variant, $quantity, $unitCost]], $date);
    }

    /**
     * Stock leaving at whatever it is currently worth.
     */
    protected function issueStock(
        Tenant $tenant,
        ItemVariant $variant,
        string $quantity,
        ?string $date = null,
    ): Transaction {
        return $this->adjustStock($tenant, [[$variant, '-'.ltrim($quantity, '-')]], $date);
    }

    /* ---------------------------------------------------------------------
     | Assertions
     |-------------------------------------------------------------------- */

    /**
     * A variant's position as three decimal strings, so an assertion reads
     * `['20.000', '15000.00', '750.00']` rather than comparing objects.
     *
     * Named `stockPositionOf` rather than `positionOf` because
     * {@see InteractsWithLedger} already has the latter for a *party's*
     * position, and a class using both traits would not be able to resolve the
     * clash — nor should it have to guess which "position" was meant.
     *
     * @return array{quantity: string, value: string, average_cost: string}
     */
    protected function stockPositionOf(Tenant $tenant, ItemVariant $variant): array
    {
        $position = $this->actingForTenant($tenant, fn () => $this->stock()->positionFor($variant));

        return [
            'quantity' => $position->quantity->amount(),
            'value' => $position->value->amount(),
            'average_cost' => $position->averageCost()->amount(),
        ];
    }

    /**
     * The invariant, asserted: **what the shelf is worth is what the Inventory
     * account says it is worth.**
     *
     * The two are written in the same database transaction from the same figure,
     * so this is a check that the engine's guarantee actually held rather than a
     * reconciliation that could legitimately differ.
     */
    protected function assertStockAgreesWithInventoryAccount(Tenant $tenant, string $because = ''): void
    {
        $stock = $this->actingForTenant($tenant, fn () => $this->stock()->totals());
        $account = $this->balanceOf($tenant, SystemAccount::Inventory);

        $this->assertSame(
            $account,
            $stock['value']->amount(),
            sprintf(
                'Stock value and the Inventory account disagree%s: shelf %s, books %s.',
                $because === '' ? '' : " {$because}",
                $stock['value']->amount(),
                $account,
            ),
        );
    }

    /**
     * @return array{quantity: string, value: string}
     */
    protected function stockTotals(Tenant $tenant): array
    {
        $totals = $this->actingForTenant($tenant, fn () => $this->stock()->totals());

        return [
            'quantity' => $totals['quantity']->amount(),
            'value' => $totals['value']->amount(),
        ];
    }
}
