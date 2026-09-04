<?php

namespace App\Services\Inventory;

use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Tenancy\TenantContext;
use App\Support\Units\UnitRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Gives a workshop its opening units and categories.
 *
 * The counterpart of {@see \App\Services\Accounting\ChartOfAccountProvisioner},
 * and it exists for the same reason: a workshop with no chart of accounts cannot
 * post, and a workshop with no units and no categories cannot record a product.
 * Called once when a tenant is provisioned, and available afterwards as a
 * backfill for tenants that predate a newly added default.
 *
 * The list itself lives in {@see CatalogueDefaults}, shared with the migration
 * that backfilled the workshops that already existed — so a workshop set up last
 * year and one set up this morning have the same catalogue vocabulary, which they
 * would not if the two lists were written out twice.
 *
 * ## Idempotent, and create-only
 *
 * A unit or category that already exists is left completely alone. A workshop
 * that renamed "Piece" to "Nos", or turned "Bulk material" off because it never
 * deals in any, must not have that undone by a later backfill — and nothing
 * resolves on the label anyway: items point at a unit *code*, and the seeded
 * categories carry the code the old `ItemType` enum used.
 */
class CatalogueProvisioner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly UnitRegistry $registry,
    ) {}

    /**
     * Create any seeded unit and category this workshop does not already have.
     *
     * @return array{units: int, categories: int, attributes: int}
     */
    public function seedFor(Tenant|int $tenant): array
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;

        return $this->context->runFor($tenant, function () use ($tenantId): array {
            $counts = DB::transaction(fn (): array => [
                'units' => $this->seedUnits(),
                ...$this->seedCategories(),
            ]);

            // The registry caches the workshop's units for the life of the
            // request, and the request that just provisioned a tenant usually
            // goes straight on to create its first product.
            $this->registry->forget($tenantId);

            return $counts;
        });
    }

    private function seedUnits(): int
    {
        $existing = Unit::query()->pluck('code')->all();

        $created = 0;
        $order = 0;

        foreach (CatalogueDefaults::units() as $unit) {
            $order += 10;

            if (in_array($unit['code'], $existing, true)) {
                continue;
            }

            Unit::create([
                'code' => $unit['code'],
                'label' => $unit['label'],
                'symbol' => $unit['symbol'],
                'kind' => $unit['kind'],
                'decimals' => $unit['decimals'],
                'is_system' => $unit['is_system'],
                'is_active' => true,
                'display_order' => $order,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * @return array{categories: int, attributes: int}
     */
    private function seedCategories(): array
    {
        $categories = 0;
        $attributes = 0;
        $order = 0;

        foreach (CatalogueDefaults::categories() as $definition) {
            $order += 10;

            if (ItemCategory::where('code', $definition['code'])->exists()) {
                continue;
            }

            $category = ItemCategory::create([
                'parent_id' => null,
                'name' => $definition['name'],
                'code' => $definition['code'],
                'description' => $definition['description'],
                'holds_stock' => $definition['holds_stock'],
                'uses_sac_code' => $definition['uses_sac_code'],
                'default_unit_code' => $definition['default_unit_code'],
                'default_hsn_sac' => null,
                // Null, not zero. The enum had no opinion about the rate, and a
                // seeded 0.00 would read as "zero rated" and put a wrong rate on
                // every product filed under it.
                'default_gst_rate' => null,
                'is_system' => true,
                'is_active' => true,
                'display_order' => $order,
            ]);

            $categories++;
            $fieldOrder = 0;

            foreach ($definition['attributes'] as $field) {
                $fieldOrder += 10;

                ItemAttribute::create([
                    'category_id' => $category->id,
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'data_type' => $field['data_type'],
                    'unit_code' => $field['unit_code'] ?? null,
                    'is_required' => $field['is_required'],
                    'default_value' => null,
                    'options' => $field['options'] ?? null,
                    'min_value' => null,
                    'max_value' => null,
                    'help_text' => null,
                    'display_order' => $fieldOrder,
                    'is_active' => true,
                ]);

                $attributes++;
            }
        }

        return ['categories' => $categories, 'attributes' => $attributes];
    }
}
