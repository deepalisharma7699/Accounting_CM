<?php

namespace App\Services\Accounting;

use App\Enums\PartyRole;
use App\Enums\TransactionType;
use App\Models\Party;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionAllocationRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Support\Money;

/**
 * The customer and vendor statements of the brief's §14 and §15 — M16.
 *
 * > Total Purchases ₹75,000 · Paid ₹60,000 · Outstanding ₹15,000
 * > INV-1001 — ₹20,000 — Paid
 * > INV-1012 — ₹35,000 — Partial
 * > INV-1030 — ₹20,000 — Unpaid
 *
 * ## Why this is not {@see PartyLedgerService}
 *
 * They answer different questions and both are wanted. The ledger is the
 * accountant's view: every journal entry that moved the party's position, in
 * date order, with a running balance — it reconciles to the control account
 * because it *is* the control account, broken out. This is the counter view:
 * one row per document, each saying what it was worth and what is left on it.
 *
 * A running balance cannot say which invoice the shortfall is on, and a list of
 * invoices cannot be reconciled against a trial balance. Bending either into the
 * other would produce something that did neither job, so they stay apart and the
 * headline totals below are taken from the ledger — the authority — rather than
 * summed from the documents, which would let the two screens disagree.
 *
 * ## What is derived, which is everything
 *
 * Nothing here is stored. The totals are sums over `journal_entries`; each
 * document's paid and due come from {@see BillService}, which reads the bill's
 * own payment split and the allocations pointed at it. There is no write path in
 * this class.
 */
class PartyStatementService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly TransactionAllocationRepositoryInterface $allocations,
        private readonly PartyLedgerService $ledger,
        private readonly BillService $bills,
    ) {}

    /**
     * A party's statement in the role asked for.
     *
     * The role is a parameter rather than read off the party record, because a
     * great many workshop counterparties are both — the dealer who buys motors
     * and sells bearings — and their two relationships are settled separately, on
     * different terms, by different people. Netting them into one figure would be
     * true and useless.
     *
     * @return array{
     *     party: Party,
     *     role: PartyRole,
     *     totals: array{billed: string, paid: string, outstanding: string},
     *     bills: array<int, array<string, mixed>>,
     *     settlements: array<int, array<string, mixed>>
     * }
     */
    public function forParty(Party $party, PartyRole $role): array
    {
        $billType = $role === PartyRole::Customer ? TransactionType::Sale : TransactionType::Purchase;
        $settlementType = $role === PartyRole::Customer ? TransactionType::Receipt : TransactionType::Payment;

        $bills = $this->transactions->postedForParty((int) $party->id, $billType);
        $settlements = $this->transactions->postedForParty((int) $party->id, $settlementType);

        // One query for the whole page rather than one per row — the reason
        // BillService::settlementUsing() exists.
        $allocated = $this->allocations->allocatedAgainstBills(
            $bills->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $summary = $this->ledger->summariesFor([$party]);
        $lifetime = $summary[(int) $party->id]['lifetime'] ?? null;
        $outstanding = $summary[(int) $party->id]['outstanding'] ?? null;

        return [
            'party' => $party,
            'role' => $role,
            'totals' => [
                // Read off the control account, not summed from the rows below.
                // The two agree — they are the same postings — and taking them
                // from the ledger means this screen and the trial balance can
                // never come apart, including for anything that moved the party's
                // position without being a bill: an opening balance, a
                // hand-written correction.
                'billed' => $role === PartyRole::Customer
                    ? ($lifetime['billed'] ?? '0.00')
                    : ($lifetime['purchased'] ?? '0.00'),
                'paid' => $role === PartyRole::Customer
                    ? ($lifetime['received'] ?? '0.00')
                    : ($lifetime['paid'] ?? '0.00'),
                'outstanding' => $role === PartyRole::Customer
                    ? ($outstanding['receivable'] ?? '0.00')
                    : ($outstanding['payable'] ?? '0.00'),
            ],
            'bills' => $bills->map(fn (Transaction $bill) => $this->billRow(
                $bill,
                $allocated[(int) $bill->id] ?? Money::zero(),
            ))->all(),
            'settlements' => $settlements->map(
                fn (Transaction $settlement) => $this->settlementRow($settlement)
            )->all(),
        ];
    }

    /**
     * One invoice, as the brief prints it: number, date, total, and where it
     * stands.
     *
     * @return array<string, mixed>
     */
    private function billRow(Transaction $bill, Money $allocated): array
    {
        // Never null in practice — the repository returned only posted bills —
        // but stated rather than assumed, because the fallback below is what a
        // reader needs to see to know the columns are always present.
        $position = $this->bills->settlementUsing($bill, $allocated);
        $status = $position['status'] ?? null;

        return [
            'id' => (int) $bill->id,
            'doc_no' => $bill->doc_no,
            'date' => $bill->date->toDateString(),
            'notes' => $bill->notes,
            'total' => $bill->totalMoney()->amount(),
            'paid' => $position['paid'] ?? '0.00',
            'due' => $position['due'] ?? '0.00',
            'payment_status' => $status?->value,
            'payment_status_label' => $status?->label(),
            'payment_status_tone' => $status?->tone(),
            'due_date' => $position['due_date'] ?? null,
        ];
    }

    /**
     * One receipt or payment, with how much of it has been pointed at a bill.
     *
     * `unallocated` is the interesting column and the reason the payment history
     * is here at all rather than being left to the transaction list: a ₹50,000
     * cheque with ₹12,000 still unapplied is money the workshop has that nobody
     * has decided the destination of, and it is invisible on every other screen.
     *
     * @return array<string, mixed>
     */
    private function settlementRow(Transaction $settlement): array
    {
        $total = $settlement->totalMoney();
        $allocated = $this->allocations->allocatedFromSettlement((int) $settlement->id);

        return [
            'id' => (int) $settlement->id,
            'doc_no' => $settlement->doc_no,
            'date' => $settlement->date->toDateString(),
            'notes' => $settlement->notes,
            'total' => $total->amount(),
            'allocated' => $allocated->amount(),
            'unallocated' => $total->minus($allocated)->amount(),
            'modes' => $settlement->relationLoaded('payments')
                ? $settlement->payments->map(fn ($payment) => [
                    'mode' => $payment->mode->value,
                    'mode_label' => $payment->mode->label(),
                    'amount' => $payment->amountMoney()->amount(),
                    'reference' => $payment->reference,
                ])->all()
                : [],
        ];
    }
}
