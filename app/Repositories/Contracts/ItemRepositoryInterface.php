<?php

namespace App\Repositories\Contracts;

use App\Models\Item;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ItemRepositoryInterface
{
    public function findById(int $id): ?Item;

    /**
     * An item with its variants — the detail read, eager-loaded so a picker is one
     * query rather than one per family.
     */
    public function findWithVariants(int $id): ?Item;

    public function nameExists(string $name, ?int $exceptId = null): bool;

    public function codeExists(string $code, ?int $exceptId = null): bool;

    /**
     * @return Collection<int, Item>
     */
    public function all(bool $activeOnly = false): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Item;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Item $item, array $attributes): Item;

    /**
     * Only ever reaches an item nothing points at — the service checks first, and
     * M8's stock movements will make the database refuse it too.
     */
    public function delete(Item $item): bool;

    public function variantCount(int $itemId): int;

    /**
     * How many items still need a person to look at them — the badge on the review
     * queue, which has to be cheap enough to fetch on every page load.
     */
    public function draftCount(): int;

    /**
     * @param  array{search?: string|null, type?: string|null, is_active?: bool|null, is_stock?: bool|null, is_draft?: bool|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, Item>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
