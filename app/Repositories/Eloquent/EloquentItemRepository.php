<?php

namespace App\Repositories\Eloquent;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Repositories\Contracts\ItemRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentItemRepository implements ItemRepositoryInterface
{
    /**
     * Columns a client may sort by, so nothing user-supplied reaches ORDER BY.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['name', 'code', 'created_at'];

    public function findById(int $id): ?Item
    {
        // `tracksStock()` and `taxCodeLabel()` both read the category, and they
        // are asked of almost every item that is resolved at all. The brand comes
        // with it because `brandLabel()` is on every serialisation.
        return Item::with(['category', 'brand'])->find($id);
    }

    public function findWithVariants(int $id): ?Item
    {
        // The category chain comes with it: the detail view draws the attribute
        // schema, and resolving it lazily would be a query per level of nesting
        // every time a product was opened.
        return Item::with([
            'variants',
            'brand',
            'category.fields',
            'category.parent.fields',
        ])->find($id);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return Item::where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return Item::where('code', $code)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function all(bool $activeOnly = false): Collection
    {
        return Item::query()
            ->with(['category', 'brand'])
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('name')
            ->get();
    }

    public function create(array $attributes): Item
    {
        return Item::create($attributes);
    }

    public function update(Item $item, array $attributes): Item
    {
        $item->fill($attributes)->save();

        return $item->refresh();
    }

    public function delete(Item $item): bool
    {
        return (bool) $item->delete();
    }

    public function variantCount(int $itemId): int
    {
        return ItemVariant::where('item_id', $itemId)->count();
    }

    public function draftCount(): int
    {
        return Item::query()->drafts()->count();
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'name';

        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        return Item::query()
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where(function ($query) use ($filters) {
                    $term = '%'.$filters['search'].'%';

                    $query->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('hsn_sac', 'like', $term)
                        // Through the master now that brand is a row rather than
                        // a typed string. Somebody searching "Crompton" is after
                        // the make, and the make lives one join away.
                        ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', $term))
                        // A fitter searching for "1440" is looking for a motor by
                        // its speed, which lives on the variant. Without this the
                        // catalogue is only searchable by family name, which is
                        // the one thing nobody remembers.
                        ->orWhereHas('variants', fn ($variants) => $variants
                            ->where('sku', 'like', $term)
                            ->orWhere('label', 'like', $term));
                })
            )
            // The caller resolves the descendants, so "Motors" means motors and
            // every kind of motor filed under it. A filter that matched only the
            // exact category would hide every product the moment somebody
            // organised their catalogue into subcategories.
            ->when(
                filled($filters['category_ids'] ?? null),
                fn ($query) => $query->whereIn('category_id', (array) $filters['category_ids'])
            )
            ->when(
                filled($filters['brand_id'] ?? null),
                fn ($query) => $query->where('brand_id', $filters['brand_id'])
            )
            ->when(
                ($filters['is_active'] ?? null) !== null,
                fn ($query) => $query->where('is_active', $filters['is_active'])
            )
            ->when(
                ($filters['is_stock'] ?? null) !== null,
                fn ($query) => $query->where('is_stock', $filters['is_stock'])
            )
            ->when(
                ($filters['is_draft'] ?? null) !== null,
                fn ($query) => $query->where('is_draft', $filters['is_draft'])
            )
            /*
            | Whether anything sits under the family, counting only what is
            | still active — an archived variant is not something a bill can be
            | written against, so a family whose only variant was retired has
            | none for this purpose.
            */
            ->when(
                ($filters['has_variants'] ?? null) !== null,
                fn ($query) => $filters['has_variants']
                    ? $query->whereHas('variants', fn ($variants) => $variants->where('is_active', true))
                    : $query->whereDoesntHave('variants', fn ($variants) => $variants->where('is_active', true))
            )
            ->with(['category', 'brand'])
            // A count rather than the variants themselves, and it is what the
            // listing's Variants column prints — always current, because it is
            // counted with the page rather than stored on the item.
            ->withCount('variants')
            ->orderBy($sort, $direction)
            // A stable tiebreaker: names are unique per workshop, but a sort by
            // code or created_at is not, and without this a page boundary can
            // repeat or skip a row.
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
