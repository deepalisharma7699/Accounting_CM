<?php

namespace App\Repositories\Contracts;

use App\Models\Item;
use App\Models\ItemVariant;
use Illuminate\Support\Collection;

interface ItemVariantRepositoryInterface
{
    public function findById(int $id): ?ItemVariant;

    /**
     * A variant with the item behind it, because almost nothing about a variant
     * means anything without its type — the attribute schema, the unit and the tax
     * code all live on the family.
     */
    public function findWithItem(int $id): ?ItemVariant;

    public function skuExists(string $sku, ?int $exceptId = null): bool;

    /**
     * @return Collection<int, ItemVariant>
     */
    public function forItem(Item $item, bool $activeOnly = false): Collection;

    /**
     * Every variant M8 has to compute a position for, with its family loaded.
     *
     * Not paginated, deliberately. A stock screen sorts by what is running out
     * and filters on whether a position is low or negative — and neither of
     * those is a column, so a page of rows chosen by the database would be a
     * page chosen before the interesting question was asked. The set is bounded
     * by what a workshop maintains by hand, and the positions behind it are one
     * query however many rows there are; M12 revisits this if a catalogue ever
     * grows past that.
     *
     * @param  array{search?: string|null, item_id?: int|null, type?: string|null, is_active?: bool|null}  $filters
     * @return Collection<int, ItemVariant>
     */
    public function stocked(array $filters = []): Collection;

    /**
     * Every variant with a matching attribute bag, so a duplicate specification can
     * be reported before a second row for the same 5 HP motor exists.
     *
     * Exact match on the stored JSON, which is meaningful because the service
     * normalises the bag into schema order first — the same reason a party's roles
     * are stored in enum order.
     *
     * @param  array<string, string>  $attributes
     * @return Collection<int, ItemVariant>
     */
    public function matchingAttributes(int $itemId, array $attributes, ?int $exceptId = null): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ItemVariant;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ItemVariant $variant, array $attributes): ItemVariant;

    public function delete(ItemVariant $variant): bool;

    public function draftCount(): int;
}
