<?php

use App\Services\Inventory\CatalogueDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move the catalogue's vocabulary out of PHP and into the tables that now hold
 * it, without changing the meaning of a single row already recorded.
 *
 * Three passes, and the ordering matters:
 *
 *   1. **Units.** Every workshop gets the seven the enum knew, at their exact
 *      codes and scales, plus the ones the trade needs and could not express.
 *   2. **Categories and their attributes.** The four `ItemType` cases become
 *      rows, keyed by the enum's own values, carrying the enum's own attribute
 *      keys. `hp` stays `hp`, so every value already in an
 *      `item_variants.attributes` bag still validates afterwards.
 *   3. **Products.** `items.category_id` is set from `items.type` by matching
 *      that code, and any `brand` sitting in a variant's bag is lifted onto the
 *      product, where brand now lives.
 *
 * ## Nothing is destroyed here
 *
 * `items.type` is left exactly as it was. It stops being read in the same slice
 * that drops it — a later migration — so that this one can be applied, inspected
 * and reversed on its own. Every insert is guarded against re-running, so a
 * workshop provisioned after this migration was written does not end up with two
 * of everything.
 *
 * Raw queries rather than Eloquent throughout, and deliberately: the models carry
 * a tenant scope that would filter this to whichever workshop happened to be in
 * context, which for a migration is none of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $tenantIds = DB::table('tenants')->pluck('id');

        foreach ($tenantIds as $tenantId) {
            $this->seedUnits((int) $tenantId, $now);
            $this->seedCategories((int) $tenantId, $now);
        }

        $this->linkItemsToCategories();
        $this->liftBrandOntoItems();
    }

    /**
     * The units, in the order they are declared, so the seven a motor workshop
     * uses hourly sort above the twenty it uses rarely.
     */
    private function seedUnits(int $tenantId, mixed $now): void
    {
        $existing = DB::table('units')->where('tenant_id', $tenantId)->pluck('code')->all();

        $rows = [];
        $order = 0;

        foreach (CatalogueDefaults::units() as $unit) {
            $order += 10;

            // Guarded rather than upserted: a workshop that has already renamed
            // "Piece" to "Nos" must not have that undone by a migration re-run.
            if (in_array($unit['code'], $existing, true)) {
                continue;
            }

            $rows[] = [
                'tenant_id' => $tenantId,
                'code' => $unit['code'],
                'label' => $unit['label'],
                'symbol' => $unit['symbol'],
                'kind' => $unit['kind'],
                'decimals' => $unit['decimals'],
                'is_system' => $unit['is_system'],
                'is_active' => true,
                'display_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('units')->insert($rows);
        }
    }

    /**
     * The four categories and their attributes.
     *
     * Inserted one at a time rather than in a batch, because each category's
     * attributes need the id the insert produces.
     */
    private function seedCategories(int $tenantId, mixed $now): void
    {
        $order = 0;

        foreach (CatalogueDefaults::categories() as $category) {
            $order += 10;

            $existingId = DB::table('item_categories')
                ->where('tenant_id', $tenantId)
                ->where('code', $category['code'])
                ->value('id');

            if ($existingId !== null) {
                continue;
            }

            $categoryId = DB::table('item_categories')->insertGetId([
                'tenant_id' => $tenantId,
                'parent_id' => null,
                'name' => $category['name'],
                'code' => $category['code'],
                'description' => $category['description'],
                'holds_stock' => $category['holds_stock'],
                'uses_sac_code' => $category['uses_sac_code'],
                'default_unit_code' => $category['default_unit_code'],
                'default_hsn_sac' => null,
                // Null, not zero. The enum had no opinion about the rate, and a
                // seeded 0.00 would read as "zero rated" and put a wrong rate on
                // every product filed under it.
                'default_gst_rate' => null,
                'is_system' => true,
                'is_active' => true,
                'display_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->seedAttributes($tenantId, (int) $categoryId, $category['attributes'], $now);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     */
    private function seedAttributes(int $tenantId, int $categoryId, array $attributes, mixed $now): void
    {
        $rows = [];
        $order = 0;

        foreach ($attributes as $attribute) {
            $order += 10;

            $rows[] = [
                'tenant_id' => $tenantId,
                'category_id' => $categoryId,
                'key' => $attribute['key'],
                'label' => $attribute['label'],
                'data_type' => $attribute['data_type'],
                'unit_code' => $attribute['unit_code'] ?? null,
                'is_required' => $attribute['is_required'],
                'default_value' => null,
                'options' => isset($attribute['options']) ? json_encode($attribute['options']) : null,
                'min_value' => null,
                'max_value' => null,
                'help_text' => null,
                'display_order' => $order,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('item_attributes')->insert($rows);
        }
    }

    /**
     * Point every existing product at the category that was its type.
     *
     * One UPDATE per (tenant, code) pair rather than a correlated subquery, so
     * the statement is readable in a slow query log and so a code that failed to
     * match leaves its items visibly unlinked rather than silently nulled.
     */
    private function linkItemsToCategories(): void
    {
        $categories = DB::table('item_categories')
            ->whereNotNull('code')
            ->get(['id', 'tenant_id', 'code']);

        foreach ($categories as $category) {
            DB::table('items')
                ->where('tenant_id', $category->tenant_id)
                ->where('type', $category->code)
                ->whereNull('category_id')
                ->update(['category_id' => $category->id]);
        }
    }

    /**
     * Lift `brand` out of the variant attribute bags and onto the product.
     *
     * Brand was an optional attribute of the `part` type and of nothing else,
     * which meant a motor could not record its make. It is a column on `items`
     * now, so the stored values move with it and the key is removed from the bag
     * — otherwise the first edit of such a variant would be refused for carrying
     * an attribute its category no longer defines.
     *
     * Where two variants of one product disagree about the brand, the first wins
     * and the rest are left in the log. That is a real if unlikely loss, and it
     * is reported rather than resolved: a product that is two brands was two
     * products.
     */
    private function liftBrandOntoItems(): void
    {
        $variants = DB::table('item_variants')
            ->whereNotNull('attributes')
            ->get(['id', 'item_id', 'attributes']);

        foreach ($variants as $variant) {
            $bag = json_decode((string) $variant->attributes, true);

            if (! is_array($bag) || ! array_key_exists('brand', $bag)) {
                continue;
            }

            $brand = trim((string) $bag['brand']);
            unset($bag['brand']);

            DB::table('item_variants')->where('id', $variant->id)->update([
                'attributes' => $bag === [] ? null : json_encode($bag),
            ]);

            if ($brand === '') {
                continue;
            }

            // Only where the product has no brand yet — first writer wins, and
            // the alternative would be the last variant read silently deciding
            // what the whole product is made by.
            DB::table('items')
                ->where('id', $variant->item_id)
                ->whereNull('brand')
                ->update(['brand' => $brand]);
        }
    }

    /**
     * Unlink the products and remove the seeded masters.
     *
     * `items.type` was never touched, so undoing this leaves the catalogue
     * exactly as it was found. The brand lift is the one thing that does not
     * reverse — the values are on `items.brand`, which the previous migration
     * drops — and that is stated rather than pretended otherwise.
     */
    public function down(): void
    {
        DB::table('items')->update(['category_id' => null]);

        DB::table('item_attributes')->delete();
        DB::table('item_categories')->delete();
        DB::table('units')->delete();
    }
};
