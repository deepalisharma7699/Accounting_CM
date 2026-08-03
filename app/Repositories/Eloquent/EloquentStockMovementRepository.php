<?php

namespace App\Repositories\Eloquent;

use App\Models\ItemVariant;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use App\Services\Accounting\Posting\StockChange;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Every read of the stock ledger goes through here, and every one of them is an
 * Eloquent query — a raw `DB::table('stock_movements')` would bypass the tenant
 * scope, which on MySQL is the entire isolation boundary.
 */
class EloquentStockMovementRepository implements StockMovementRepositoryInterface
{
    /**
     * @param  array<int, StockChange>  $changes
     * @return Collection<int, StockMovement>
     */
    public function writeFor(Transaction $transaction, array $changes, ?Collection $documentLines = null): Collection
    {
        $movements = new Collection;

        foreach (array_values($changes) as $index => $change) {
            $movements->push($transaction->stockMovements()->create([
                // Stamped from the parent rather than from the context, so a
                // movement can never end up in a different workshop's stock from
                // the transaction that owns it — the same rule the journal
                // entries follow.
                'tenant_id' => $transaction->tenant_id,
                'item_id' => $change->itemId,
                'variant_id' => $change->variantId,
                // Paired by line number, because the line did not exist when the
                // change was composed. Null where there is no document at all.
                'transaction_line_id' => $change->lineNo === null
                    ? null
                    : $documentLines?->get($change->lineNo)?->id,
                'type' => $change->type,
                'line_no' => $index + 1,
                'quantity' => $change->quantity->amount(),
                'unit_cost' => $change->unitCost->amount(),
                'value' => $change->value->amount(),
                'date' => $transaction->date,
                'memo' => $change->memo,
            ]));
        }

        return $movements;
    }

    public function lockForValuation(array $variantIds): void
    {
        $variantIds = array_values(array_unique(array_filter($variantIds)));

        if ($variantIds === []) {
            return;
        }

        // Ordered, so two transactions moving the same two variants take the
        // locks in the same sequence and cannot deadlock against each other.
        sort($variantIds);

        ItemVariant::query()
            ->whereIn('id', $variantIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    public function positionFor(int $variantId, ?string $to = null): array
    {
        $row = StockMovement::query()
            ->forVariant($variantId)
            ->when(filled($to), fn ($query) => $query->upTo($to))
            ->selectRaw('COALESCE(SUM(quantity), 0) as qty_total, COALESCE(SUM(value), 0) as value_total')
            ->first();

        return [
            'quantity' => (string) ($row?->qty_total ?? '0'),
            'value' => (string) ($row?->value_total ?? '0'),
        ];
    }

    public function positionsFor(array $variantIds, ?string $to = null): Collection
    {
        if ($variantIds === []) {
            return new Collection;
        }

        return StockMovement::query()
            ->forVariants($variantIds)
            ->when(filled($to), fn ($query) => $query->upTo($to))
            ->groupBy('variant_id')
            ->selectRaw(
                'variant_id, COALESCE(SUM(quantity), 0) as qty_total, COALESCE(SUM(value), 0) as value_total'
            )
            ->get()
            ->map(fn (StockMovement $row) => [
                'variant_id' => (int) $row->variant_id,
                'quantity' => (string) $row->qty_total,
                'value' => (string) $row->value_total,
            ]);
    }

    public function lastArrivalCostFor(int $variantId): ?string
    {
        $cost = StockMovement::query()
            ->forVariant($variantId)
            ->where('quantity', '>', 0)
            // By id, not by date: the last cost the workshop actually learned,
            // which is what a back-dated arrival does not change.
            ->orderByDesc('id')
            ->value('unit_cost');

        return $cost === null ? null : (string) $cost;
    }

    public function forVariant(int $variantId, array $filters, int $perPage): LengthAwarePaginator
    {
        return StockMovement::query()
            ->forVariant($variantId)
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->from($filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->upTo($filters['to']))
            ->with(['transaction:id,type,status,source,date,notes,party_id'])
            ->inStockOrder()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function positionBefore(int $variantId, string $date, int $movementId): array
    {
        $row = StockMovement::query()
            ->forVariant($variantId)
            // Stock order is (date, id), so "before this movement" is everything
            // on an earlier day plus everything recorded earlier the same day.
            ->where(function ($query) use ($date, $movementId) {
                $query->whereDate('date', '<', $date)
                    ->orWhere(fn ($sameDay) => $sameDay->whereDate('date', '=', $date)->where('id', '<', $movementId));
            })
            ->selectRaw('COALESCE(SUM(quantity), 0) as qty_total, COALESCE(SUM(value), 0) as value_total')
            ->first();

        return [
            'quantity' => (string) ($row?->qty_total ?? '0'),
            'value' => (string) ($row?->value_total ?? '0'),
        ];
    }

    public function totals(?string $to = null): array
    {
        $row = StockMovement::query()
            ->when(filled($to), fn ($query) => $query->upTo($to))
            ->selectRaw('COALESCE(SUM(quantity), 0) as qty_total, COALESCE(SUM(value), 0) as value_total')
            ->first();

        return [
            'quantity' => (string) ($row?->qty_total ?? '0'),
            'value' => (string) ($row?->value_total ?? '0'),
        ];
    }

    public function countForVariant(int $variantId): int
    {
        return StockMovement::query()->forVariant($variantId)->count();
    }

    public function countForItem(int $itemId): int
    {
        return StockMovement::query()->forItem($itemId)->count();
    }

    public function movedVariantIds(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return StockMovement::query()
            ->forVariants($variantIds)
            ->distinct()
            ->pluck('variant_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
