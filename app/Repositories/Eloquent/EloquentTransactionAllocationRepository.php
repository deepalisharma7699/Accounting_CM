<?php

namespace App\Repositories\Eloquent;

use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Repositories\Contracts\TransactionAllocationRepositoryInterface;
use App\Support\Money;
use Illuminate\Support\Collection;

class EloquentTransactionAllocationRepository implements TransactionAllocationRepositoryInterface
{
    public function writeFor(Transaction $settlement, array $allocations): Collection
    {
        $written = new Collection;

        foreach ($allocations as $allocation) {
            $written->push(TransactionAllocation::create([
                'tenant_id' => $settlement->tenant_id,
                'settlement_transaction_id' => $settlement->id,
                'bill_transaction_id' => $allocation['bill_transaction_id'],
                'amount' => $allocation['amount']->amount(),
            ]));
        }

        return $written;
    }

    public function clearFor(Transaction $settlement): int
    {
        return TransactionAllocation::query()
            ->where('settlement_transaction_id', $settlement->id)
            ->delete();
    }

    public function allocatedAgainstBill(int $billId): Money
    {
        return Money::of(
            TransactionAllocation::query()
                ->where('bill_transaction_id', $billId)
                ->sum('amount') ?: 0
        );
    }

    public function allocatedAgainstBills(array $billIds): array
    {
        // Every id present in the answer, at zero where nothing was allocated.
        // A caller should never have to tell "nothing paid" apart from "not
        // fetched", which is the bug an absent key invites.
        $totals = array_fill_keys($billIds, Money::zero());

        if ($billIds === []) {
            return $totals;
        }

        $rows = TransactionAllocation::query()
            ->selectRaw('bill_transaction_id, SUM(amount) as aggregate')
            ->whereIn('bill_transaction_id', $billIds)
            ->groupBy('bill_transaction_id')
            ->pluck('aggregate', 'bill_transaction_id');

        foreach ($rows as $billId => $amount) {
            $totals[(int) $billId] = Money::of((string) $amount);
        }

        return $totals;
    }

    public function allocatedFromSettlement(int $settlementId): Money
    {
        return Money::of(
            TransactionAllocation::query()
                ->where('settlement_transaction_id', $settlementId)
                ->sum('amount') ?: 0
        );
    }

    public function forSettlement(int $settlementId): Collection
    {
        return TransactionAllocation::query()
            ->where('settlement_transaction_id', $settlementId)
            ->with('bill:id,doc_no,type,date,total,status')
            ->orderBy('id')
            ->get();
    }

    public function forBill(int $billId): Collection
    {
        return TransactionAllocation::query()
            ->where('bill_transaction_id', $billId)
            ->with('settlement:id,doc_no,type,date,total,status')
            ->orderBy('id')
            ->get();
    }
}
