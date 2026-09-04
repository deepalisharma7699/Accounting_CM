<?php

namespace App\Repositories\Eloquent;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Repositories\Contracts\ItemVariantRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentItemVariantRepository implements ItemVariantRepositoryInterface
{
    public function findById(int $id): ?ItemVariant
    {
        return ItemVariant::find($id);
    }

    public function findWithItem(int $id): ?ItemVariant
    {
        // The category comes too, and it has to: `tracksStock()`, the tax-code
        // label and the whole attribute schema hang off it, and a variant is
        // almost never resolved without at least one of them being asked.
        return ItemVariant::with(['item.category.fields', 'item.category.parent.fields'])->find($id);
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        return ItemVariant::where('sku', $sku)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function barcodeExists(string $barcode, ?int $exceptId = null): bool
    {
        return ItemVariant::where('barcode', $barcode)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function forItem(Item $item, bool $activeOnly = false): Collection
    {
        return ItemVariant::where('item_id', $item->id)
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('label')
            ->orderBy('id')
            ->get();
    }

    public function stocked(array $filters = []): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return ItemVariant::query()
            ->stocked()
            ->with(['item.category'])
            // Archived variants keep their stock history and can still hold a
            // position, so "active only" is a default rather than a rule — a
            // workshop looking for the last four of something they have stopped
            // selling needs to be able to find them.
            ->when((bool) ($filters['is_active'] ?? true), fn ($query) => $query->active())
            ->when(filled($filters['item_id'] ?? null), fn ($query) => $query->where('item_id', $filters['item_id']))
            // Filtered on the category the product is filed under, and on every
            // category beneath it — the caller resolves the descendants, because
            // "Motors" on a filter means motors and the four kinds of motor
            // under it.
            ->when(filled($filters['category_ids'] ?? null), fn ($query) => $query->whereHas(
                'item',
                fn ($item) => $item->whereIn('category_id', (array) $filters['category_ids']),
            ))
            // A fitter looking for "1440" is after a motor by its speed, and the
            // family name is the one thing nobody remembers — so the search
            // reaches the variant's own label and SKU as well as the item's.
            ->when($search !== '', fn ($query) => $query->where(function ($outer) use ($search) {
                $like = '%'.$search.'%';

                $outer->where('label', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhereHas('item', fn ($item) => $item
                        ->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        // Through the Brand Master, which is where a make lives
                        // now that it is a row rather than a typed string.
                        ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', $like)));
            }))
            ->orderBy('item_id')
            ->orderBy('label')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, string>  $attributes
     * @return Collection<int, ItemVariant>
     */
    public function matchingAttributes(int $itemId, array $attributes, ?int $exceptId = null): Collection
    {
        if ($attributes === []) {
            return new Collection;
        }

        return ItemVariant::where('item_id', $itemId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            // Every named attribute must match. whereJsonContains per key rather
            // than a single comparison of the whole document, because a variant
            // that also carries an optional attribute the caller did not mention
            // is still the same specification — a second 5 HP / 1440 row is a
            // duplicate whether or not somebody typed its frame size.
            ->where(function ($query) use ($attributes) {
                foreach ($attributes as $key => $value) {
                    $query->whereJsonContains("attributes->{$key}", (string) $value);
                }
            })
            ->orderBy('id')
            ->get();
    }

    public function create(array $attributes): ItemVariant
    {
        return ItemVariant::create($attributes);
    }

    public function update(ItemVariant $variant, array $attributes): ItemVariant
    {
        $variant->fill($attributes)->save();

        return $variant->refresh();
    }

    public function delete(ItemVariant $variant): bool
    {
        return (bool) $variant->delete();
    }

    public function draftCount(): int
    {
        return ItemVariant::query()->drafts()->count();
    }
}
