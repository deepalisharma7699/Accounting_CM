<?php

namespace App\Repositories\Eloquent;

use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\StockMovement;
use App\Models\TransactionLine;
use App\Models\Unit;
use App\Models\WorkshopJobPart;
use App\Repositories\Contracts\UnitRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentUnitRepository implements UnitRepositoryInterface
{
    public function findById(int $id): ?Unit
    {
        return Unit::find($id);
    }

    public function findByCode(string $code): ?Unit
    {
        return Unit::where('code', $code)->first();
    }

    public function all(array $filters = []): Collection
    {
        return Unit::query()
            ->when(
                filled($filters['search'] ?? null),
                function ($query) use ($filters) {
                    $term = '%'.$filters['search'].'%';

                    $query->where(fn ($q) => $q
                        ->where('label', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('symbol', 'like', $term));
                }
            )
            ->when(filled($filters['kind'] ?? null), fn ($query) => $query->where('kind', $filters['kind']))
            ->when(
                ($filters['is_active'] ?? null) !== null,
                fn ($query) => $query->where('is_active', $filters['is_active'])
            )
            ->ordered()
            ->get();
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return Unit::where('code', $code)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function labelExists(string $label, ?int $exceptId = null): bool
    {
        return Unit::where('label', $label)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function create(array $attributes): Unit
    {
        return Unit::create($attributes);
    }

    public function update(Unit $unit, array $attributes): Unit
    {
        $unit->fill($attributes)->save();

        return $unit->refresh();
    }

    public function delete(Unit $unit): bool
    {
        return (bool) $unit->delete();
    }

    public function itemCountForCode(string $code): int
    {
        return Item::where('base_uom', $code)->count();
    }

    /**
     * Posted lines and job parts together, because the refusal they produce is
     * the same one: neither can be re-pointed at a different unit, so a unit
     * either of them names can only ever be switched off.
     */
    public function documentLineCountForCode(string $code): int
    {
        return TransactionLine::where('unit', $code)->count()
            + WorkshopJobPart::where('unit', $code)->count();
    }

    public function attributeCountForCode(string $code): int
    {
        return ItemAttribute::where('unit_code', $code)->count();
    }

    /**
     * The widest scale any recorded quantity actually uses.
     *
     * Both tables are swept because they answer different halves of the question:
     * `stock_movements` is what is on the shelf and `transaction_lines` is what
     * was billed, and a unit narrowed on the strength of one would still round
     * the other.
     *
     * The scale is computed in PHP rather than SQL. It could be done with string
     * functions in the query, but the expression differs per driver and this runs
     * once, from a settings screen, over the rows of a single unit.
     */
    public function widestRecordedScale(string $code): array
    {
        $itemIds = Item::where('base_uom', $code)->pluck('id');

        $quantities = new Collection;

        if ($itemIds->isNotEmpty()) {
            $quantities = $quantities->merge(
                StockMovement::whereIn('item_id', $itemIds)->pluck('quantity')
            );
        }

        $quantities = $quantities->merge(
            TransactionLine::where('unit', $code)->pluck('quantity')
        )->merge(
            WorkshopJobPart::where('unit', $code)->pluck('quantity')
        );

        $scale = 0;
        $example = null;

        foreach ($quantities as $quantity) {
            $text = rtrim(rtrim((string) $quantity, '0'), '.');
            $dot = strpos($text, '.');

            if ($dot === false) {
                continue;
            }

            $places = strlen($text) - $dot - 1;

            if ($places > $scale) {
                $scale = $places;
                $example = $text;
            }
        }

        return ['scale' => $scale, 'example' => $example];
    }
}
