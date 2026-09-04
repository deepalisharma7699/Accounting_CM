<?php

namespace App\Repositories\Eloquent;

use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariant;
use App\Repositories\Contracts\ItemAttributeRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EloquentItemAttributeRepository implements ItemAttributeRepositoryInterface
{
    public function findById(int $id): ?ItemAttribute
    {
        return ItemAttribute::find($id);
    }

    public function forCategory(ItemCategory $category, bool $activeOnly = false): Collection
    {
        return ItemAttribute::where('category_id', $category->id)
            ->when($activeOnly, fn ($query) => $query->active())
            ->ordered()
            ->get();
    }

    public function keyExists(int $categoryId, string $key, ?int $exceptId = null): bool
    {
        return ItemAttribute::where('category_id', $categoryId)
            ->where('key', $key)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function create(array $attributes): ItemAttribute
    {
        return ItemAttribute::create($attributes);
    }

    public function update(ItemAttribute $attribute, array $attributes): ItemAttribute
    {
        $attribute->fill($attributes)->save();

        return $attribute->refresh();
    }

    public function delete(ItemAttribute $attribute): bool
    {
        return (bool) $attribute->delete();
    }

    public function valueCountForKey(string $key, array $categoryIds): int
    {
        if ($categoryIds === [] || ! $this->isSafeKey($key)) {
            return 0;
        }

        return $this->underCategories($categoryIds)
            ->whereNotNull('attributes->'.$key)
            ->count();
    }

    public function missingValueCountForKey(string $key, array $categoryIds): int
    {
        if ($categoryIds === [] || ! $this->isSafeKey($key)) {
            return 0;
        }

        return $this->underCategories($categoryIds)
            ->whereNull('attributes->'.$key)
            ->count();
    }

    public function distinctValuesForKey(string $key, array $categoryIds): array
    {
        if ($categoryIds === [] || ! $this->isSafeKey($key)) {
            return [];
        }

        return $this->underCategories($categoryIds)
            ->whereNotNull('attributes->'.$key)
            // Plucked and uniqued in PHP rather than DISTINCT in SQL: the JSON
            // extraction returns a quoted scalar on MySQL, and unquoting it in
            // the query is driver-specific for no gain over a dozen rows.
            ->pluck('attributes')
            ->map(fn ($bag) => is_array($bag) ? ($bag[$key] ?? null) : null)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values()
            ->all();
    }

    public function nextDisplayOrder(int $categoryId): int
    {
        $highest = (int) ItemAttribute::where('category_id', $categoryId)->max('display_order');

        // Tens, so a field can be dropped between two without renumbering the
        // rest. The reorder endpoint renumbers anyway; this is for the common
        // case where nobody has.
        return $highest + 10;
    }

    public function reorder(ItemCategory $category, array $orderedIds): void
    {
        $position = 0;

        foreach ($orderedIds as $id) {
            $position += 10;

            ItemAttribute::where('category_id', $category->id)
                ->whereKey($id)
                ->update(['display_order' => $position]);
        }
    }

    /**
     * Variants whose product is filed under any of these categories.
     *
     * @param  array<int, int>  $categoryIds
     * @return Builder<ItemVariant>
     */
    private function underCategories(array $categoryIds): Builder
    {
        return ItemVariant::query()
            ->whereHas('item', fn (Builder $item) => $item->whereIn('category_id', $categoryIds));
    }

    /**
     * Whether a key is safe to interpolate into a JSON path.
     *
     * `ItemAttributeService` already refuses anything that is not snake case on
     * the way in, so this can only fire for a hand-written row — and a JSON path
     * is one of the few places in this codebase where a value reaches SQL
     * unparameterised, so it is checked rather than trusted.
     */
    private function isSafeKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1;
    }
}
