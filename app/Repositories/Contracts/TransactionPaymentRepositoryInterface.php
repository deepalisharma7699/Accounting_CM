<?php

namespace App\Repositories\Contracts;

use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Services\Accounting\Posting\PaymentSplit;
use Illuminate\Support\Collection;

/**
 * Settlement rows. Note what is absent, exactly as with the ledger: no update
 * and no delete, because a settlement is written once alongside the entries it
 * describes and a posted transaction is never edited.
 */
interface TransactionPaymentRepositoryInterface
{
    /**
     * Write a transaction's settlement split. Called only by the posting engine,
     * and only inside the database transaction that wrote its journal entries —
     * a settlement without its entries would be a record of money that never
     * moved.
     *
     * @param  array<int, PaymentSplit>  $splits
     * @return Collection<int, TransactionPayment>
     */
    public function writeFor(Transaction $transaction, array $splits): Collection;

    /**
     * Totals by mode over a period — "the day's UPI collections", "every cheque
     * written in March". The reconciliation read, and the reason `mode` is
     * indexed.
     *
     * @return Collection<int, array{mode: string, total: string, count: int}>
     */
    public function totalsByMode(?string $from = null, ?string $to = null): Collection;
}
