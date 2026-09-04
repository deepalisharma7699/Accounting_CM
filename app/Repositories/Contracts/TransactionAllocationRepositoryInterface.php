<?php

namespace App\Repositories\Contracts;

use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Support\Money;
use Illuminate\Support\Collection;

interface TransactionAllocationRepositoryInterface
{
    /**
     * Write a settlement's allocations. Called from inside the caller's database
     * transaction, so a partly-allocated receipt can never be left behind.
     *
     * @param  array<int, array{bill_transaction_id: int, amount: Money}>  $allocations
     * @return Collection<int, TransactionAllocation>
     */
    public function writeFor(Transaction $settlement, array $allocations): Collection;

    /**
     * Remove a settlement's allocations, so a fresh set can replace them.
     *
     * Deleting rather than reversing, which is the opposite of how this
     * application treats everything else — and correct here for the same reason
     * the whole table sits outside the ledger. An allocation carries no
     * accounting consequence: nothing was posted when it was written and nothing
     * is unposted when it goes. Keeping a superseded one would leave the bill
     * looking more settled than it is.
     *
     * @return int  How many were removed.
     */
    public function clearFor(Transaction $settlement): int;

    /**
     * What has been allocated against one bill, from every settlement.
     */
    public function allocatedAgainstBill(int $billId): Money;

    /**
     * The same figure for a page of bills at once, keyed by bill id — so a
     * listing costs one query rather than one per row.
     *
     * @param  array<int, int>  $billIds
     * @return array<int, Money>
     */
    public function allocatedAgainstBills(array $billIds): array;

    /**
     * How much of a settlement has been pointed at a bill — as opposed to how
     * much of it there is. The difference is what is still free to allocate.
     */
    public function allocatedFromSettlement(int $settlementId): Money;

    /**
     * The allocations on a settlement, with the bills they point at loaded.
     *
     * @return Collection<int, TransactionAllocation>
     */
    public function forSettlement(int $settlementId): Collection;

    /**
     * The allocations against a bill, with the settlements behind them loaded —
     * "who paid this, and when".
     *
     * @return Collection<int, TransactionAllocation>
     */
    public function forBill(int $billId): Collection;
}
