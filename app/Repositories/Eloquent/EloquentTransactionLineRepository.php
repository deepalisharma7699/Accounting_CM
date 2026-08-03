<?php

namespace App\Repositories\Eloquent;

use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Repositories\Contracts\TransactionLineRepositoryInterface;
use App\Services\Accounting\Posting\BillLine;
use Illuminate\Support\Collection;

class EloquentTransactionLineRepository implements TransactionLineRepositoryInterface
{
    /**
     * @param  array<int, BillLine>  $lines
     * @return Collection<int, TransactionLine>
     */
    public function writeFor(Transaction $transaction, array $lines): Collection
    {
        $written = new Collection;

        foreach (array_values($lines) as $index => $line) {
            $attributes = $line->toArray();

            // The template numbers its own lines, but the position in the array
            // is what the unique index and the stock pairing actually agree on —
            // so it wins, and a template that numbered badly cannot produce two
            // rows claiming to be line 3.
            $attributes['line_no'] = $index + 1;

            // Stamped from the parent rather than from the context, so a line can
            // never end up on a different workshop's invoice from the transaction
            // that owns it — the rule every write-once table here follows.
            $attributes['tenant_id'] = $transaction->tenant_id;

            $written->put($attributes['line_no'], $transaction->lines()->create($attributes));
        }

        return $written;
    }

    public function forTransaction(int $transactionId): Collection
    {
        return TransactionLine::query()
            ->where('transaction_id', $transactionId)
            ->with(['stockMovement', 'item:id,name,type,base_uom', 'variant:id,item_id,sku,label,attributes'])
            ->orderBy('line_no')
            ->get();
    }

    public function countForVariant(int $variantId): int
    {
        return TransactionLine::query()->forVariant($variantId)->count();
    }

    public function countForItem(int $itemId): int
    {
        return TransactionLine::query()->where('item_id', $itemId)->count();
    }

    public function gstTotals(?string $from = null, ?string $to = null): Collection
    {
        return TransactionLine::query()
            // A join rather than whereHas, because the grouping key — what kind
            // of document this line was on — lives on the transaction and has to
            // be selectable. Matching tenant_id as well as the foreign key keeps
            // the boundary stated on both sides of the join, column to column,
            // exactly as the party totals do.
            ->join('transactions', function ($join) {
                $join->on('transactions.id', '=', 'transaction_lines.transaction_id')
                    ->on('transactions.tenant_id', '=', 'transaction_lines.tenant_id');
            })
            ->when(filled($from), fn ($query) => $query->whereDate('transactions.date', '>=', $from))
            ->when(filled($to), fn ($query) => $query->whereDate('transactions.date', '<=', $to))
            ->groupBy('transactions.type', 'transaction_lines.gst_rate')
            ->orderBy('transactions.type')
            ->orderBy('transaction_lines.gst_rate')
            ->selectRaw(
                'transactions.type as document_type, transaction_lines.gst_rate as rate, '.
                'COALESCE(SUM(transaction_lines.taxable_value), 0) as taxable_total, '.
                'COALESCE(SUM(transaction_lines.cgst_amount), 0) as cgst_total, '.
                'COALESCE(SUM(transaction_lines.sgst_amount), 0) as sgst_total, '.
                'COALESCE(SUM(transaction_lines.igst_amount), 0) as igst_total, '.
                'COUNT(*) as line_count'
            )
            ->get()
            ->map(fn (TransactionLine $row) => [
                'type' => (string) $row->document_type,
                'gst_rate' => (string) $row->rate,
                'taxable' => (string) $row->taxable_total,
                'cgst' => (string) $row->cgst_total,
                'sgst' => (string) $row->sgst_total,
                'igst' => (string) $row->igst_total,
                'lines' => (int) $row->line_count,
            ]);
    }
}
