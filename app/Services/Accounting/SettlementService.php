<?php

namespace App\Services\Accounting;

use App\Exceptions\Accounting\InvalidAllocationException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Repositories\Contracts\TransactionAllocationRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deciding which invoices a receipt paid — M16.
 *
 * The ledger already knows the money arrived and whose balance it reduced. What
 * it cannot know is which of a customer's four open bills the ₹50,000 was meant
 * for, because no journal entry records that decision. This service is where the
 * decision is made and written, and it is the only thing in the application that
 * writes {@see TransactionAllocation}.
 *
 * ## Two ways of saying it
 *
 * **Explicitly** — the operator names the bills and the amounts, which is what
 * happens when a customer says "this cheque is for invoice 1012". The split is
 * taken as given and checked against what is actually owing.
 *
 * **Oldest first** — nobody said, so the money is applied to the oldest open bill
 * until it is settled, then the next. That is the default because it is what
 * accounts departments everywhere do, and because the alternative — leaving the
 * oldest debt behind newer ones the customer keeps paying — is how a receivable
 * quietly ages past the point of being collectable.
 *
 * ## What it refuses
 *
 * Over-allocation, in both directions: more than the bill has left owing, and
 * more than the receipt itself is worth. Both are refused rather than clamped —
 * see {@see InvalidAllocationException} for why silently absorbing the difference
 * is the worse failure.
 *
 * ## What it does not touch
 *
 * The ledger. Allocating changes no journal entry, no balance and no total; the
 * money moved when the receipt was posted, and that is unaffected by which
 * invoice somebody later says it was for. Re-allocating a receipt is therefore
 * safe in a way that re-posting one would never be.
 */
class SettlementService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly TransactionAllocationRepositoryInterface $allocations,
        private readonly BillService $bills,
    ) {}

    /**
     * Point a posted receipt or payment at the bills it settles.
     *
     * **Replaces** whatever it was pointed at before, rather than adding to it.
     * That is what "this cheque was for invoice 1030, not 1001" means, and it is
     * the only reading under which the operation can be repeated: appending
     * would make a correction impossible, since the receipt's money is already
     * spoken for by the allocation being corrected.
     *
     * Superseded rows are deleted outright — the one place in this application
     * where a record is removed rather than reversed, and it is safe for the
     * same reason the whole table sits outside the ledger. No entry was posted
     * when the allocation was written and none is unposted when it goes; the
     * money has not moved and does not move now.
     *
     * @param  array<int, array{bill_transaction_id: int|string, amount?: int|string|float|null}>  $splits
     *                                       An explicit split, or empty for
     *                                       oldest-first across everything the
     *                                       party has open.
     * @return Collection<int, TransactionAllocation>
     *
     * @throws InvalidAllocationException
     */
    public function allocate(Transaction $settlement, array $splits = []): Collection
    {
        $this->assertSettleable($settlement);

        // One wrapper around clearing, reading what is owing, and writing.
        // Without it, two receipts posted at the same instant would both read the
        // same ₹5,000 as outstanding on one invoice and both allocate against it,
        // leaving the bill showing more paid than it is worth — the same race the
        // document numbering closes, in a different table. And a re-allocation
        // that failed its checks would have already thrown the old rows away.
        return DB::transaction(function () use ($settlement, $splits) {
            $this->allocations->clearFor($settlement);

            $planned = $splits === []
                ? $this->planOldestFirst($settlement)
                : $this->planFromSplits($settlement, $splits);

            $this->assertWithinSettlement($settlement, $planned);

            return $this->allocations->writeFor($settlement, $planned);
        });
    }

    /**
     * What is left of a settlement to point at anything — its total less
     * whatever has already been allocated from it.
     *
     * The figure a screen shows as "₹12,000 unapplied", and the ceiling every
     * allocation is checked against.
     */
    public function unallocated(Transaction $settlement): Money
    {
        return $settlement->totalMoney()
            ->minus($this->allocations->allocatedFromSettlement((int) $settlement->id));
    }

    /**
     * What a settlement has already been pointed at, as a client reads it back:
     * one row per bill, with the number a human recognises.
     *
     * @return array<int, array{id: int, doc_no: string|null, date: string, amount: string}>
     */
    public function allocationsOf(Transaction $settlement): array
    {
        return $this->allocations->forSettlement((int) $settlement->id)
            ->map(fn (TransactionAllocation $allocation) => [
                'id' => (int) $allocation->bill_transaction_id,
                'doc_no' => $allocation->bill?->doc_no,
                'date' => $allocation->bill?->date?->toDateString() ?? '',
                'amount' => $allocation->amountMoney()->amount(),
            ])
            ->all();
    }

    /**
     * A party's open bills of the kind this settlement can pay, with what is
     * still owing on each — the list a "which invoices is this for?" picker
     * renders.
     *
     * @return array<int, array{transaction: Transaction, due: Money}>
     */
    public function openBillsFor(Transaction $settlement): array
    {
        $billType = $settlement->type->settlesBillType();

        if ($billType === null || $settlement->party_id === null) {
            return [];
        }

        $bills = $this->transactions->postedForParty((int) $settlement->party_id, $billType);
        $allocated = $this->allocations->allocatedAgainstBills(
            $bills->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $open = [];

        foreach ($bills as $bill) {
            $due = $this->dueOn($bill, $allocated[(int) $bill->id] ?? Money::zero());

            // Settled bills are dropped rather than listed at zero. This is a
            // worklist, and a picker padded with everything the customer has ever
            // paid is a picker nobody can find the right row in.
            if ($due->isPositive()) {
                $open[] = ['transaction' => $bill, 'due' => $due];
            }
        }

        return $open;
    }

    /* ---------------------------------------------------------------------
     | Planning
     |-------------------------------------------------------------------- */

    /**
     * Spread what is unapplied across the party's open bills, oldest first.
     *
     * Stops when the money runs out, which is the ordinary case — a customer
     * paying ₹50,000 against ₹80,000 of open invoices settles the first two and
     * part of the third. Stopping is not an error: the remainder stays on their
     * account and reduces their balance exactly as it always did.
     *
     * @return array<int, array{bill_transaction_id: int, amount: Money}>
     */
    private function planOldestFirst(Transaction $settlement): array
    {
        $remaining = $this->unallocated($settlement);
        $planned = [];

        foreach ($this->openBillsFor($settlement) as $open) {
            if (! $remaining->isPositive()) {
                break;
            }

            // The smaller of what is owing and what is left of the receipt, so
            // the last bill takes a part-payment rather than being skipped.
            $amount = $open['due']->compareTo($remaining) <= 0 ? $open['due'] : $remaining;

            $planned[] = [
                'bill_transaction_id' => (int) $open['transaction']->id,
                'amount' => $amount,
            ];

            $remaining = $remaining->minus($amount);
        }

        return $planned;
    }

    /**
     * Take the operator's split as given, and check every line of it.
     *
     * An amount left out means "whatever is still owing on this one", which is
     * what somebody ticking three invoices off a list actually means and saves
     * them retyping figures the server already knows.
     *
     * @param  array<int, array{bill_transaction_id: int|string, amount?: int|string|float|null}>  $splits
     * @return array<int, array{bill_transaction_id: int, amount: Money}>
     *
     * @throws InvalidAllocationException
     */
    private function planFromSplits(Transaction $settlement, array $splits): array
    {
        // Folded by bill *before* anything is checked. Two lines naming invoice
        // 1012 are one allocation of the combined amount — the unique index would
        // refuse a second row anyway — and folding first is what lets each bill
        // be measured against what it owes exactly once, whichever way the
        // operator typed it.
        //
        // Null means "whatever is still owing", which is what ticking an invoice
        // off a list means and saves retyping a figure the server already knows.
        /** @var array<int, Money|null> $requested */
        $requested = [];

        foreach ($splits as $split) {
            $billId = (int) $split['bill_transaction_id'];
            $amount = isset($split['amount']) && $split['amount'] !== ''
                ? Money::of($split['amount'])
                : null;

            if (! array_key_exists($billId, $requested)) {
                $requested[$billId] = $amount;

                continue;
            }

            // "The rest" absorbs a stated figure rather than adding to it: a
            // line asking for the full remaining balance already covers the
            // other, and summing the two would ask for more than exists.
            $requested[$billId] = $amount === null || $requested[$billId] === null
                ? null
                : $requested[$billId]->plus($amount);
        }

        $planned = [];

        foreach ($requested as $billId => $amount) {
            $bill = $this->billFor($settlement, $billId);
            $due = $this->dueOn($bill, $this->allocations->allocatedAgainstBill($billId));

            $amount ??= $due;

            // A bill that is already settled contributes nothing rather than
            // failing the whole receipt. Somebody ticking four invoices where one
            // was paid this morning meant the other three.
            if (! $amount->isPositive()) {
                continue;
            }

            if ($amount->compareTo($due) > 0) {
                throw InvalidAllocationException::exceedsBillDue(
                    $bill->doc_no ?? "#{$bill->id}",
                    $amount->amount(),
                    $due->amount(),
                );
            }

            $planned[] = ['bill_transaction_id' => $billId, 'amount' => $amount];
        }

        return $planned;
    }

    /* ---------------------------------------------------------------------
     | The refusals
     |-------------------------------------------------------------------- */

    /**
     * @throws InvalidAllocationException
     */
    private function assertSettleable(Transaction $settlement): void
    {
        if ($settlement->type->settlesBillType() === null) {
            throw InvalidAllocationException::notASettlement(
                (int) $settlement->id,
                $settlement->type->label(),
            );
        }

        if (! $settlement->isPosted()) {
            throw InvalidAllocationException::notInTheBooks(
                (int) $settlement->id,
                $settlement->status->label(),
            );
        }
    }

    /**
     * The bill a split names — of this workshop, of the right kind, in the
     * books, and belonging to the same party as the money.
     *
     * @throws InvalidAllocationException|ResourceNotFoundException
     */
    private function billFor(Transaction $settlement, int $billId): Transaction
    {
        // The tenant scope does the isolation: another workshop's id simply does
        // not resolve, so a guessed number cannot reach across the boundary.
        $bill = $this->transactions->findById($billId)
            ?? throw new ResourceNotFoundException('Transaction', $billId);

        if ($bill->type !== $settlement->type->settlesBillType()) {
            throw InvalidAllocationException::notABill((int) $bill->id, $bill->type->label());
        }

        if (! $bill->isPosted()) {
            throw InvalidAllocationException::notInTheBooks((int) $bill->id, $bill->status->label());
        }

        if ((int) $bill->party_id !== (int) $settlement->party_id) {
            $bill->loadMissing('party');
            $settlement->loadMissing('party');

            throw InvalidAllocationException::partyMismatch(
                $bill->doc_no ?? "#{$bill->id}",
                $settlement->party?->name ?? 'this party',
                $bill->party?->name ?? 'another party',
            );
        }

        return $bill;
    }

    /**
     * The plan must fit inside the settlement it is spending.
     *
     * @param  array<int, array{bill_transaction_id: int, amount: Money}>  $planned
     *
     * @throws InvalidAllocationException
     */
    private function assertWithinSettlement(Transaction $settlement, array $planned): void
    {
        $total = Money::sum(array_map(fn (array $line) => $line['amount'], $planned));
        $available = $this->unallocated($settlement);

        if ($total->compareTo($available) > 0) {
            throw InvalidAllocationException::exceedsSettlement(
                $total->amount(),
                $available->amount(),
            );
        }
    }

    /**
     * What is still owing on a bill, given what has already been allocated to it.
     *
     * The bill's own at-counter payments come out of {@see BillService}, so this
     * and the Paid column on every screen are one computation rather than two
     * that have to agree.
     */
    private function dueOn(Transaction $bill, Money $allocated): Money
    {
        $position = $this->bills->settlementUsing($bill, $allocated);

        // Null for anything that is not a posted bill, which `billFor()` has
        // already refused — so reaching here means the caller is looking at a
        // document with no position, and nothing is owing on it.
        return $position === null ? Money::zero() : Money::of($position['due']);
    }
}
