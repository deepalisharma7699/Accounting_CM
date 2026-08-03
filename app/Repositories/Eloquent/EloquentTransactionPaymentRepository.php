<?php

namespace App\Repositories\Eloquent;

use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Repositories\Contracts\TransactionPaymentRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Eloquent throughout, never a raw `DB::table('transaction_payments')` — on
 * MySQL the global tenant scope is the entire isolation boundary, and a raw
 * query walks straight past it.
 */
class EloquentTransactionPaymentRepository implements TransactionPaymentRepositoryInterface
{
    /**
     * @param  array<int, \App\Services\Accounting\Posting\PaymentSplit>  $splits
     * @return Collection<int, TransactionPayment>
     */
    public function writeFor(Transaction $transaction, array $splits): Collection
    {
        $rows = new Collection;

        foreach (array_values($splits) as $index => $split) {
            $rows->push($transaction->payments()->create([
                // Stamped from the parent rather than from the context, so a
                // settlement can never end up in a different workshop's books
                // from the transaction that owns it.
                'tenant_id' => $transaction->tenant_id,
                'mode' => $split->mode,
                'amount' => $split->amount->amount(),
                'reference' => $split->reference,
                'line_no' => $index + 1,
            ]));
        }

        return $rows;
    }

    /**
     * @return Collection<int, array{mode: string, total: string, count: int}>
     */
    public function totalsByMode(?string $from = null, ?string $to = null): Collection
    {
        return TransactionPayment::query()
            // The date lives on the transaction, not here: a settlement is dated
            // by the event it belongs to, and duplicating that would give the two
            // a way to disagree.
            ->when(
                filled($from) || filled($to),
                fn ($query) => $query->whereHas('transaction', fn ($transaction) => $transaction
                    ->when(filled($from), fn ($q) => $q->whereDate('date', '>=', $from))
                    ->when(filled($to), fn ($q) => $q->whereDate('date', '<=', $to)))
            )
            ->selectRaw('mode, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('mode')
            ->get()
            ->map(fn (TransactionPayment $row) => [
                'mode' => $row->getRawOriginal('mode'),
                'total' => (string) $row->getAttribute('total'),
                'count' => (int) $row->getAttribute('count'),
            ]);
    }
}
