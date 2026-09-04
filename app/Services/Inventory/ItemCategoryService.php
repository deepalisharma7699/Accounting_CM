<?php

namespace App\Services\Inventory;

use App\Exceptions\Accounting\CatalogueMasterException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\ItemCategory;
use App\Repositories\Contracts\ItemCategoryRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The Category Master: what the shop asks about each kind of thing it deals in.
 *
 * This is the service that made the inventory generic. Before it, the four kinds
 * a product could be — motor, part, bulk material, service — were an enum, and
 * adding "Water Pump" or "LED Light" or "Apparel" meant editing PHP. Now it is a
 * row, and the universal create form reads it.
 *
 * ## What is refused, and why each refusal exists
 *
 *   * **Deleting a category with products under it.** They would be left
 *     describing themselves in terms nothing defines. Archiving is offered
 *     instead, which takes it off the create form and leaves it explaining what
 *     is already filed.
 *   * **Deleting a category with subcategories.** Same reasoning, one level up:
 *     the children inherit fields from it.
 *   * **Deleting one of the four seeded categories.** Posted documents and
 *     existing products refer to what they mean.
 *   * **A cycle in the parent chain.** MySQL will not express `parent_id <> id`
 *     as a CHECK against an auto-increment column, so the whole family of cycles
 *     — including the one-step kind — is refused here.
 *   * **Marking a category as holding no stock while stocked products sit under
 *     it.** Refused rather than cascaded: silently clearing `is_stock` on twelve
 *     products would take them off the stock report with nothing to say why,
 *     while their movements stayed in the ledger.
 *
 * ## What is deliberately *not* refused
 *
 * Renaming, re-describing, reordering and archiving are all free, including on
 * the seeded four. A workshop that calls them something else in its own trade
 * should say so, and none of that changes what any product means.
 */
class ItemCategoryService
{
    public function __construct(
        private readonly ItemCategoryRepositoryInterface $categories,
        private readonly ItemAttributeService $attributes,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array{search?: string|null, is_active?: bool|null, parent_id?: int|null}  $filters
     * @return Collection<int, ItemCategory>
     */
    public function all(array $filters = []): Collection
    {
        return $this->categories->all($filters);
    }

    public function find(int $id): ItemCategory
    {
        return $this->categories->findById($id)
            ?? throw new ResourceNotFoundException('Category', $id);
    }

    /**
     * A category with its whole inherited question set loaded — what the
     * universal form is built from.
     */
    public function findWithSchema(int $id): ItemCategory
    {
        return $this->categories->findWithSchema($id)
            ?? throw new ResourceNotFoundException('Category', $id);
    }

    /**
     * The question set in the shape the form reads, keyed by attribute key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schemaFor(ItemCategory $category): array
    {
        return $category->attributeSchema();
    }

    /**
     * What is filed under a category, for the master screen and for every
     * refusal that needs a number in it.
     *
     * @return array{items: int, stocked_items: int, children: int}
     */
    public function usageFor(ItemCategory $category): array
    {
        return [
            'items' => $this->categories->itemCount((int) $category->id),
            'stocked_items' => $this->categories->stockedItemCount((int) $category->id),
            'children' => $this->categories->childCount((int) $category->id),
        ];
    }

    /**
     * This category and everything beneath it — the listing filter that means
     * "Motors, and the four kinds of motor under it".
     *
     * @return array<int, int>
     */
    public function descendantIds(int $categoryId): array
    {
        return $this->categories->descendantIds($categoryId);
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ItemCategory
    {
        $name = trim((string) $data['name']);
        $code = $this->normaliseCode($data['code'] ?? null);
        $parent = $this->resolveParent($data['parent_id'] ?? null);

        $this->assertNameAvailable($name);

        if ($code !== null) {
            $this->assertCodeAvailable($code);
        }

        $category = $this->categories->create([
            'parent_id' => $parent?->id,
            'name' => $name,
            'code' => $code,
            'description' => $this->trimmed($data['description'] ?? null),
            // Defaulted from the parent where there is one, so a subcategory of
            // Motor is a stocked, HSN-coded thing without anybody saying so
            // again.
            'holds_stock' => (bool) ($data['holds_stock'] ?? $parent?->holds_stock ?? true),
            'uses_sac_code' => (bool) ($data['uses_sac_code'] ?? $parent?->uses_sac_code ?? false),
            'default_unit_code' => $this->trimmed($data['default_unit_code'] ?? null) ?? $parent?->default_unit_code,
            'default_hsn_sac' => $this->trimmed($data['default_hsn_sac'] ?? null) ?? $parent?->default_hsn_sac,
            'default_gst_rate' => $this->normaliseRate($data['default_gst_rate'] ?? null) ?? $parent?->default_gst_rate,
            'is_system' => false,
            'is_active' => true,
            'display_order' => (int) ($data['display_order'] ?? 0),
        ]);

        Log::info('item_categories.created', [
            'category_id' => $category->id,
            'parent_id' => $category->parent_id,
        ]);

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): ItemCategory
    {
        $category = $this->find($id);
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name !== $category->name) {
                $this->assertNameAvailable($name, (int) $category->id);
                $attributes['name'] = $name;
            }
        }

        if (array_key_exists('code', $data)) {
            $code = $this->normaliseCode($data['code']);

            if ($code !== $category->code) {
                if ($code !== null) {
                    $this->assertCodeAvailable($code, (int) $category->id);
                }

                $attributes['code'] = $code;
            }
        }

        if (array_key_exists('parent_id', $data)) {
            $parent = $this->resolveParent($data['parent_id']);

            $this->assertNoCycle($category, $parent);

            $attributes['parent_id'] = $parent?->id;
        }

        foreach (['description', 'default_unit_code', 'default_hsn_sac'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $this->trimmed($data[$field]);
            }
        }

        if (array_key_exists('default_gst_rate', $data)) {
            $attributes['default_gst_rate'] = $this->normaliseRate($data['default_gst_rate']);
        }

        if (array_key_exists('holds_stock', $data)) {
            $holdsStock = (bool) $data['holds_stock'];

            if (! $holdsStock && $category->holds_stock) {
                $this->assertNothingStillStocked($category);
            }

            $attributes['holds_stock'] = $holdsStock;
        }

        if (array_key_exists('uses_sac_code', $data)) {
            $attributes['uses_sac_code'] = (bool) $data['uses_sac_code'];
        }

        if (array_key_exists('display_order', $data)) {
            $attributes['display_order'] = (int) $data['display_order'];
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($attributes === []) {
            return $category;
        }

        $category = $this->categories->update($category, $attributes);

        Log::info('item_categories.updated', [
            'category_id' => $category->id,
            'fields' => array_keys($attributes),
        ]);

        return $category;
    }

    /**
     * Remove a category nothing depends on.
     *
     * Its attribute definitions go with it — they cascade, and rightly: "Flow
     * Rate" means nothing without the category that asked for it. Anything with
     * products or children under it is refused and told to archive instead.
     */
    public function delete(int $id): void
    {
        $category = $this->find($id);

        if ($category->isProtected()) {
            throw CatalogueMasterException::categoryProtected((int) $category->id, $category->name);
        }

        $usage = $this->usageFor($category);

        if ($usage['items'] > 0) {
            throw CatalogueMasterException::categoryHasItems(
                (int) $category->id,
                $category->name,
                $usage['items'],
            );
        }

        if ($usage['children'] > 0) {
            throw CatalogueMasterException::categoryHasChildren(
                (int) $category->id,
                $category->name,
                $usage['children'],
            );
        }

        $this->categories->delete($category);

        Log::info('item_categories.deleted', ['category_id' => $id]);
    }

    /* ---------------------------------------------------------------------
     | Templates
     |-------------------------------------------------------------------- */

    /**
     * Create a category from one of {@see CatalogueDefaults::templates()}.
     *
     * The point is to make §16's acceptance test — "configure LED Light, use it
     * immediately" — a single click for the shapes people actually ask for,
     * without seeding anybody's catalogue with categories they do not deal in.
     *
     * What it produces is an ordinary category. Nothing about it is privileged:
     * the admin can rename it, add fields, remove fields or delete it outright a
     * minute later. If a category of that name already exists the template is
     * refused rather than merged — quietly adding six fields to a category
     * somebody has already tuned is not a thing they asked for.
     *
     * One database transaction, because a category whose fields half-arrived is
     * worse than no category: the form would draw an incomplete specification and
     * nobody would know which half was missing.
     */
    public function applyTemplate(string $name): ItemCategory
    {
        $template = collect(CatalogueDefaults::templates())
            ->first(fn (array $candidate) => strcasecmp($candidate['name'], $name) === 0);

        if ($template === null) {
            throw new ResourceNotFoundException('Category template', 0);
        }

        $this->assertNameAvailable($template['name']);

        return DB::transaction(function () use ($template) {
            $category = $this->create([
                'name' => $template['name'],
                'description' => $template['description'],
                'holds_stock' => $template['holds_stock'],
                'uses_sac_code' => $template['uses_sac_code'],
                'default_unit_code' => $template['default_unit_code'],
            ]);

            foreach ($template['attributes'] as $attribute) {
                $this->attributes->create($category, $attribute);
            }

            Log::info('item_categories.template_applied', [
                'category_id' => $category->id,
                'template' => $template['name'],
            ]);

            return $this->findWithSchema((int) $category->id);
        });
    }

    /**
     * The templates still worth offering — the ones whose name is not already
     * taken, so the picker never shows a choice that would be refused.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableTemplates(): array
    {
        $taken = $this->categories->all()->map(fn (ItemCategory $category) => strtolower($category->name))->all();

        return array_values(array_filter(
            CatalogueDefaults::templates(),
            fn (array $template) => ! in_array(strtolower($template['name']), $taken, true),
        ));
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    /**
     * Refuse a parent that is the category itself or one of its own descendants.
     *
     * The descendant sweep is what makes this more than the CHECK the database
     * could not hold: filing Motor under Submersible Motor is a legal-looking
     * edit that would leave both of them inheriting from each other.
     */
    private function assertNoCycle(ItemCategory $category, ?ItemCategory $parent): void
    {
        if ($parent === null) {
            return;
        }

        $forbidden = $this->categories->descendantIds((int) $category->id);

        if (in_array((int) $parent->id, $forbidden, true)) {
            throw CatalogueMasterException::categoryCycle($category->name);
        }
    }

    private function assertNothingStillStocked(ItemCategory $category): void
    {
        $stocked = $this->categories->stockedItemCount((int) $category->id);

        if ($stocked === 0) {
            return;
        }

        throw CatalogueMasterException::categoryStillStocks(
            (int) $category->id,
            $category->name,
            $stocked,
        );
    }

    private function resolveParent(mixed $parentId): ?ItemCategory
    {
        if ($parentId === null || $parentId === '' || (int) $parentId === 0) {
            return null;
        }

        return $this->find((int) $parentId);
    }

    private function assertNameAvailable(string $name, ?int $exceptId = null): void
    {
        if ($this->categories->nameExists($name, $exceptId)) {
            throw CatalogueMasterException::categoryNameTaken($name);
        }
    }

    private function assertCodeAvailable(string $code, ?int $exceptId = null): void
    {
        if (! $this->categories->codeExists($code, $exceptId)) {
            return;
        }

        throw CatalogueMasterException::categoryNameTaken($code);
    }

    private function normaliseCode(?string $code): ?string
    {
        $code = strtolower(trim((string) $code));

        return $code === '' ? null : substr($code, 0, 40);
    }

    /**
     * A rate as a two-decimal string, or null where the category has no opinion.
     *
     * Null and zero are different answers and the difference matters: null means
     * "ask", zero means "zero rated", and defaulting one to the other would put a
     * wrong rate on every product filed under the category.
     */
    private function normaliseRate(mixed $rate): ?string
    {
        if ($rate === null || $rate === '') {
            return null;
        }

        return number_format((float) $rate, 2, '.', '');
    }

    private function trimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
