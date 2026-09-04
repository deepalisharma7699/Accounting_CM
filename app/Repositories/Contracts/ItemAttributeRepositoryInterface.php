<?php

namespace App\Repositories\Contracts;

use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use Illuminate\Support\Collection;

interface ItemAttributeRepositoryInterface
{
    public function findById(int $id): ?ItemAttribute;

    /**
     * @return Collection<int, ItemAttribute>
     */
    public function forCategory(ItemCategory $category, bool $activeOnly = false): Collection;

    public function keyExists(int $categoryId, string $key, ?int $exceptId = null): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ItemAttribute;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ItemAttribute $attribute, array $attributes): ItemAttribute;

    public function delete(ItemAttribute $attribute): bool;

    /**
     * How many variants have actually recorded a value under this key.
     *
     * A JSON containment question, and the reason attributes are switched off
     * rather than deleted: the values live in `item_variants.attributes` and
     * nothing rewrites a thousand bags to remove a field somebody regretted.
     *
     * Scoped to the categories the attribute governs, so counting "size" under
     * Apparel does not sweep in every bearing that has one too.
     *
     * @param  array<int, int>  $categoryIds
     */
    public function valueCountForKey(string $key, array $categoryIds): int;

    /**
     * How many variants under these categories are *missing* a value for the key
     * — the question behind refusing to make a field compulsory.
     *
     * @param  array<int, int>  $categoryIds
     */
    public function missingValueCountForKey(string $key, array $categoryIds): int;

    /**
     * The distinct values recorded under this key, so a dropdown's option list
     * can be checked against what products actually hold before it is narrowed.
     *
     * @param  array<int, int>  $categoryIds
     * @return array<int, string>
     */
    public function distinctValuesForKey(string $key, array $categoryIds): array;

    /**
     * The next display position in a category, so a new field lands at the bottom
     * rather than fighting an existing one for a slot.
     */
    public function nextDisplayOrder(int $categoryId): int;

    /**
     * Reorder a category's fields in one pass.
     *
     * @param  array<int, int>  $orderedIds
     */
    public function reorder(ItemCategory $category, array $orderedIds): void;
}
