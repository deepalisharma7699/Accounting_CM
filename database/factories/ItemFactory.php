<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemBrand;
use App\Models\ItemCategory;
use App\Services\Inventory\CatalogueDefaults;
use App\Services\Inventory\CatalogueProvisioner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // tenant_id is stamped by BelongsToTenant from the current context.
            'name' => fake()->unique()->words(2, true).' '.fake()->randomNumber(3),
            'code' => null,
            'category_id' => fn () => self::categoryId('part'),
            'brand_id' => null,
            'hsn_sac' => '8483',
            'gst_rate' => '18.00',
            'base_uom' => 'piece',
            'is_stock' => true,
            'is_draft' => false,
            'description' => null,
            'is_active' => true,
        ];
    }

    /**
     * A category, with the unit and stock capability that come with it — so a
     * factory cannot produce the one combination the service refuses, a
     * non-stock category holding stock.
     *
     * Takes the category *code* rather than a model, because the four seeded
     * codes are the old `ItemType` values verbatim and every existing test that
     * said `ofType(ItemType::Motor)` means `ofCategory('motor')`.
     */
    public function ofCategory(string $code): static
    {
        return $this->state(function (array $attributes) use ($code) {
            $category = self::category($code);

            return [
                'category_id' => $category->id,
                'base_uom' => $category->default_unit_code ?? 'piece',
                'is_stock' => (bool) $category->holds_stock,
                'hsn_sac' => $category->uses_sac_code ? '998719' : ($attributes['hsn_sac'] ?? '8483'),
            ];
        });
    }

    public function motor(): static
    {
        return $this->ofCategory('motor')->state(fn () => [
            'name' => 'Induction Motor '.fake()->unique()->randomNumber(4),
            'hsn_sac' => '8501',
        ]);
    }

    public function part(): static
    {
        return $this->ofCategory('part');
    }

    public function bulkMaterial(): static
    {
        return $this->ofCategory('bulk_material')->state(fn () => [
            'name' => 'Copper Winding Wire '.fake()->unique()->randomNumber(4),
            'hsn_sac' => '7408',
        ]);
    }

    public function service(): static
    {
        return $this->ofCategory('service')->state(fn () => [
            'name' => 'Rewinding Labour '.fake()->unique()->randomNumber(4),
        ]);
    }

    /**
     * The seeded category with this code, provisioning the workshop's catalogue
     * first if nobody has.
     *
     * A test that builds a tenant with `Tenant::factory()` bypasses
     * {@see \App\Services\Tenancy\TenantService}, so it never gets the units and
     * categories a real workshop is provisioned with. Rather than making every
     * such test remember to seed them, the first item that needs one seeds them —
     * idempotently, through the same provisioner a real workshop uses, so a test
     * fixture and a real catalogue can never diverge.
     */
    private static function category(string $code): ItemCategory
    {
        $category = ItemCategory::where('code', $code)->first();

        if ($category !== null) {
            return $category;
        }

        app(CatalogueProvisioner::class)->seedFor(
            app(\App\Support\Tenancy\TenantContext::class)->requireTenant(Item::class)
        );

        return ItemCategory::where('code', $code)->firstOrFail();
    }

    private static function categoryId(string $code): int
    {
        return (int) self::category($code)->id;
    }

    /**
     * The default categories, for a test that wants them without an item.
     *
     * @return array<int, string>
     */
    public static function seededCategoryCodes(): array
    {
        return array_column(CatalogueDefaults::categories(), 'code');
    }

    /**
     * Auto-created by an importer or the capture agent and not yet reviewed.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['is_draft' => true]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Filed under a brand, creating the master row if the workshop has none by
     * that name — a factory should not make a test invent the Brand Master
     * before it can say "a Crompton motor".
     */
    public function branded(string $name = 'Crompton'): static
    {
        return $this->state(fn () => [
            'brand_id' => ItemBrand::firstOrCreate(['name' => $name])->id,
        ]);
    }
}
