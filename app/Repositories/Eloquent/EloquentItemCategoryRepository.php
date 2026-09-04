<?php

namespace App\Repositories\Eloquent;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Repositories\Contracts\ItemCategoryRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentItemCategoryRepository implements ItemCategoryRepositoryInterface
{
    /**
     * How deep the eager-loaded parent chain goes.
     *
     * The UI offers one level of nesting and this allows five, which is the
     * difference between "what we expect" and "what a hand-written row could
     * produce". `ItemCategory::resolvedAttributes()` walks lazily past this if it
     * ever has to, so a deeper tree is slower rather than wrong.
     */
    private const ANCESTRY = 'parent.parent.parent.parent.parent';

    public function findById(int $id): ?ItemCategory
    {
        return ItemCategory::find($id);
    }

    public function findWithSchema(int $id): ?ItemCategory
    {
        return ItemCategory::with([
            'fields',
            self::ANCESTRY,
            // The ancestors' attributes too — they are half the resolved schema,
            // and without this the walk is a query per level.
            'parent.fields',
            'parent.parent.fields',
            'parent.parent.parent.fields',
            'parent.parent.parent.parent.fields',
            'parent.parent.parent.parent.parent.fields',
        ])->find($id);
    }

    public function findByCode(string $code): ?ItemCategory
    {
        return ItemCategory::where('code', $code)->first();
    }

    public function all(array $filters = []): Collection
    {
        return ItemCategory::query()
            ->when(
                filled($filters['search'] ?? null),
                function ($query) use ($filters) {
                    $term = '%'.$filters['search'].'%';

                    $query->where(fn ($q) => $q
                        ->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('description', 'like', $term));
                }
            )
            ->when(
                ($filters['is_active'] ?? null) !== null,
                fn ($query) => $query->where('is_active', $filters['is_active'])
            )
            ->when(
                array_key_exists('parent_id', $filters),
                fn ($query) => $filters['parent_id'] === null
                    ? $query->whereNull('parent_id')
                    : $query->where('parent_id', $filters['parent_id'])
            )
            ->with(['fields', 'parent'])
            // One query for every category's product count, rather than one per
            // row — the listing shows it against each, and the master screen is
            // the one place somebody is deciding what to archive.
            ->withCount(['items', 'children'])
            ->ordered()
            ->get();
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return ItemCategory::where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return ItemCategory::where('code', $code)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function create(array $attributes): ItemCategory
    {
        return ItemCategory::create($attributes);
    }

    public function update(ItemCategory $category, array $attributes): ItemCategory
    {
        $category->fill($attributes)->save();

        return $category->refresh();
    }

    public function delete(ItemCategory $category): bool
    {
        return (bool) $category->delete();
    }

    public function itemCount(int $categoryId): int
    {
        return Item::where('category_id', $categoryId)->count();
    }

    public function stockedItemCount(int $categoryId): int
    {
        return Item::where('category_id', $categoryId)->where('is_stock', true)->count();
    }

    public function childCount(int $categoryId): int
    {
        return ItemCategory::where('parent_id', $categoryId)->count();
    }

    /**
     * Breadth-first rather than recursive SQL, because MySQL 8's recursive CTE
     * would have to be written round the tenant scope by hand and the tree is a
     * dozen rows deep at worst. The visited set is what stops a bad `parent_id`
     * from looping — see the category migration on why the database cannot
     * refuse a cycle itself.
     */
    public function descendantIds(int $categoryId): array
    {
        $found = [$categoryId];
        $frontier = [$categoryId];

        while ($frontier !== []) {
            $children = ItemCategory::whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => in_array($id, $found, true))
                ->values()
                ->all();

            if ($children === []) {
                break;
            }

            $found = array_merge($found, $children);
            $frontier = $children;
        }

        return $found;
    }
}
