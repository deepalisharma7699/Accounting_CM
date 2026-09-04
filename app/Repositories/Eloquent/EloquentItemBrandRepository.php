<?php

namespace App\Repositories\Eloquent;

use App\Models\Item;
use App\Models\ItemBrand;
use App\Repositories\Contracts\ItemBrandRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentItemBrandRepository implements ItemBrandRepositoryInterface
{
    public function findById(int $id): ?ItemBrand
    {
        return ItemBrand::find($id);
    }

    public function findByName(string $name): ?ItemBrand
    {
        return ItemBrand::where('name', $name)->first();
    }

    public function all(array $filters = []): Collection
    {
        return ItemBrand::query()
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
            // One query for every brand's product count rather than one per row.
            // The master screen shows it against each, and it is what decides
            // whether the delete control is offered at all.
            ->withCount('items')
            ->ordered()
            ->get();
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return ItemBrand::where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return ItemBrand::where('code', $code)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function create(array $attributes): ItemBrand
    {
        return ItemBrand::create($attributes);
    }

    public function update(ItemBrand $brand, array $attributes): ItemBrand
    {
        $brand->fill($attributes)->save();

        return $brand->refresh();
    }

    public function delete(ItemBrand $brand): bool
    {
        return (bool) $brand->delete();
    }

    public function itemCount(int $brandId): int
    {
        return Item::where('brand_id', $brandId)->count();
    }
}
