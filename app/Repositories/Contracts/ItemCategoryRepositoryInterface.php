<?php

namespace App\Repositories\Contracts;

use App\Models\ItemCategory;
use Illuminate\Support\Collection;

interface ItemCategoryRepositoryInterface
{
    public function findById(int $id): ?ItemCategory;

    /**
     * A category with everything the universal form needs to draw its fields:
     * its own attributes and its ancestors' — which is why the parent chain is
     * eager-loaded rather than walked lazily.
     */
    public function findWithSchema(int $id): ?ItemCategory;

    public function findByCode(string $code): ?ItemCategory;

    /**
     * Every category, with its attributes and item counts loaded.
     *
     * Not paginated, and deliberately: a shop has tens of categories, not
     * thousands, and every screen that shows them — the master, the create form's
     * dropdown, the list filter — wants all of them at once. Paging a picker is
     * how a user ends up unable to find the category they just made.
     *
     * @param  array{search?: string|null, is_active?: bool|null, parent_id?: int|null}  $filters
     * @return Collection<int, ItemCategory>
     */
    public function all(array $filters = []): Collection;

    public function nameExists(string $name, ?int $exceptId = null): bool;

    public function codeExists(string $code, ?int $exceptId = null): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ItemCategory;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ItemCategory $category, array $attributes): ItemCategory;

    public function delete(ItemCategory $category): bool;

    public function itemCount(int $categoryId): int;

    /**
     * How many products under this category are actually inventoried — the
     * question behind refusing to mark a category as holding no stock.
     */
    public function stockedItemCount(int $categoryId): int;

    public function childCount(int $categoryId): int;

    /**
     * The ids of this category and everything beneath it, for the listing filter
     * that means "and its subcategories".
     *
     * @return array<int, int>
     */
    public function descendantIds(int $categoryId): array;
}
