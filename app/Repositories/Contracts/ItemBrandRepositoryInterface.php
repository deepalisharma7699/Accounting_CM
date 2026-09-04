<?php

namespace App\Repositories\Contracts;

use App\Models\ItemBrand;
use Illuminate\Support\Collection;

interface ItemBrandRepositoryInterface
{
    public function findById(int $id): ?ItemBrand;

    public function findByName(string $name): ?ItemBrand;

    /**
     * Every brand, with its product count loaded.
     *
     * Not paginated, and deliberately — the same reasoning the Category Master
     * carries: a shop has tens of brands, not thousands, and every screen that
     * shows them (the master, the create form's dropdown, the list filter) wants
     * all of them at once. Paging a picker is how a user ends up unable to find
     * the brand they made a minute ago.
     *
     * @param  array{search?: string|null, is_active?: bool|null}  $filters
     * @return Collection<int, ItemBrand>
     */
    public function all(array $filters = []): Collection;

    public function nameExists(string $name, ?int $exceptId = null): bool;

    public function codeExists(string $code, ?int $exceptId = null): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ItemBrand;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ItemBrand $brand, array $attributes): ItemBrand;

    public function delete(ItemBrand $brand): bool;

    /**
     * How many products carry this brand — the number behind refusing to delete
     * one, and the number the master screen prints against each row.
     */
    public function itemCount(int $brandId): int;
}
