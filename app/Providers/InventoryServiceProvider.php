<?php

namespace App\Providers;

use App\Repositories\Contracts\ItemAttributeRepositoryInterface;
use App\Repositories\Contracts\ItemBrandRepositoryInterface;
use App\Repositories\Contracts\ItemCategoryRepositoryInterface;
use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Repositories\Contracts\ItemVariantRepositoryInterface;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use App\Repositories\Contracts\UnitRepositoryInterface;
use App\Repositories\Eloquent\EloquentItemAttributeRepository;
use App\Repositories\Eloquent\EloquentItemBrandRepository;
use App\Repositories\Eloquent\EloquentItemCategoryRepository;
use App\Repositories\Eloquent\EloquentItemRepository;
use App\Repositories\Eloquent\EloquentItemVariantRepository;
use App\Repositories\Eloquent\EloquentStockMovementRepository;
use App\Repositories\Eloquent\EloquentUnitRepository;
use App\Support\Units\UnitRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the inventory layer: the catalogue in M7, stock movements and weighted
 * average cost in M8.
 *
 * Separate from {@see AccountingServiceProvider} because the catalogue is not
 * accounting — M7 posts nothing and has no template. M8 is where the two meet:
 * the stock ledger is registered here, and it is written through the accounting
 * module's posting engine, inside the same database transaction as the journal
 * entries that value it.
 *
 * The ordering in `bootstrap/providers.php` is presentational only — every
 * binding here is lazy, so the posting engine resolving a stock repository
 * registered "after" it is not a cycle.
 */
class InventoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const REPOSITORIES = [
        ItemRepositoryInterface::class => EloquentItemRepository::class,
        ItemVariantRepositoryInterface::class => EloquentItemVariantRepository::class,
        StockMovementRepositoryInterface::class => EloquentStockMovementRepository::class,

        // The catalogue's vocabulary: what kinds of thing the shop deals in,
        // what each kind is asked to record, and how any of it is counted.
        // These replaced the ItemType and UnitOfMeasure enums.
        ItemCategoryRepositoryInterface::class => EloquentItemCategoryRepository::class,
        ItemAttributeRepositoryInterface::class => EloquentItemAttributeRepository::class,
        ItemBrandRepositoryInterface::class => EloquentItemBrandRepository::class,
        UnitRepositoryInterface::class => EloquentUnitRepository::class,
    ];

    public function register(): void
    {
        foreach (self::REPOSITORIES as $contract => $implementation) {
            $this->app->singleton($contract, $implementation);
        }

        // A singleton because it *is* a cache: it holds the workshop's units for
        // the life of the request so `$item->base_uom` — read on every row of
        // every listing and every bill line — is not a query. See UnitRegistry.
        $this->app->singleton(UnitRegistry::class);
    }
}
