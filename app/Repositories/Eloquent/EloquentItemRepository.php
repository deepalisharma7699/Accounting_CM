<?php

namespace App\Repositories\Eloquent;

use App\Enums\ItemType;
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
        return Item::find($id);
    }

    public function findWithVariants(int $id): ?Item
    {
        return Item::with('variants')->find($id);
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
                        // A fitter searching for "1440" is looking for a motor by
                        // its speed, which lives on the variant. Without this the
                        // catalogue is only searchable by family name, which is
                        // the one thing nobody remembers.
                        ->orWhereHas('variants', fn ($variants) => $variants
                            ->where('sku', 'like', $term)
                            ->orWhere('label', 'like', $term));
                })
            )
            ->when(
                filled($filters['type'] ?? null) && ItemType::tryFrom((string) $filters['type']) !== null,
                fn ($query) => $query->where('type', $filters['type'])
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
            // A count rather than the variants themselves: the list shows "4
            // variants", and loading every variant of every item to learn that is
            // the classic listing-page mistake.
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
