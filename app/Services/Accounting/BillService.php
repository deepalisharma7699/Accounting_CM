<?php

namespace App\Services\Accounting;

use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\TransactionAllocationRepositoryInterface;
use App\Repositories\Contracts\TransactionLineRepositoryInterface;
use App\Services\Accounting\Tax\GstBreakdown;
use App\Services\Inventory\StockLedgerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reading a bill back: what it was worth, what it cost, and what somebody ought
 * to look at.
 *
 * Everything here is **derived on read**, and none of it is stored. A margin is
 * the line's taxable value minus the value of the stock movement behind it — one
 * join, no duplicate. The tax summary is a sum over the lines. The warnings are
 * questions asked of today's stock position.
 *
 * ## Why warnings are computed here and not at posting
 *
 * Because they have to appear more than once. A bill sold below cost is worth
 * flagging when it is posted *and* every time somebody opens it afterwards —
 * "why is this month's margin down" is asked long after the toast has gone. A
 * warning raised only at the moment of writing is a warning half the workshop
 * misses.
 *
 * And because none of them is a refusal. Selling below cost is a real decision;
 * so is billing something the shelf says is not there. The roadmap is explicit:
 * both post.
 */
class BillService
{
    /**
     * The workshop rows read for payment terms, kept for the life of the
     * request. See {@see tenantOf()}.
     *
     * @var array<int, Tenant|null>
     */
    private array $tenants = [];

    public function __construct(
        private readonly TransactionLineRepositoryInterface $lines,
        private readonly TransactionAllocationRepositoryInterface $allocations,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly StockLedgerService $stock,
    ) {}

    /* ---------------------------------------------------------------------
     | What is owed on the document — M16
     |-------------------------------------------------------------------- */

    /**
     * A bill's money position: what it is worth, what has been settled against
     * it, what is left, and what to call that.
     *
     * Two sources add up to `paid`, and they are genuinely different things:
     *
     *   * the document's **own** payment split — cash taken at the counter when
     *     the bill was written, which lives in `transaction_payments`;
     *   * every later receipt **allocated** to it, which lives in
     *     `transaction_allocations`.
     *
     * A counter sale settled on the spot has only the first. A thirty-day
     * invoice paid by cheque has only the second. A part-paid job has both, and
     * counting either one alone is how a bill comes to be chased for money that
     * is already in the bank.
     *
     * Recomputed on every read and stored nowhere. Reversing the receipt moves
     * this figure by itself, with nothing to remember to update — which is the
     * same rule party outstanding and stock on hand follow, for the same reason.
     *
     * **Null for anything that is not a posted bill.** A draft has settled
     * nothing because nothing has moved; a reversed bill has been cancelled, so
     * it has no position rather than a nil one; and a journal has no total that
     * "paid in full" would mean anything against. Reporting zero for any of them
     * would put a figure on a screen that invites somebody to chase it.
     *
     * @return array{total: string, paid: string, due: string, status: PaymentStatus, due_date: string|null}|null
     */
    public function settlementFor(Transaction $bill): ?array
    {
        if (! $bill->type->isBillable() || ! $bill->isPosted()) {
            return null;
        }

        return $this->settlementUsing(
            $bill,
            $this->allocations->allocatedAgainstBill((int) $bill->id),
            $this->creditedAgainst($bill),
        );
    }

    /**
     * The same answer from an allocation total the caller already has.
     *
     * The entry point for anything working over more than one bill — a listing,
     * a statement, {@see SettlementService}'s oldest-first planner — all of which
     * fetch the allocations for a whole page in one query and would otherwise pay
     * for a second query per row to get the identical figure back.
     *
     * Sharing this method rather than each caller doing its own subtraction is
     * the point: there is one definition of paid, one of due and one of the
     * status, so a bills list and a customer statement cannot come to different
     * conclusions about the same invoice.
     *
     * @return array{total: string, paid: string, due: string, status: PaymentStatus, due_date: string|null}|null
     */
    public function settlementUsing(Transaction $bill, Money $allocated, ?Money $credited = null): ?array
    {
        if (! $bill->type->isBillable() || ! $bill->isPosted()) {
            return null;
        }

        return $this->positionFrom(
            $bill,
            $this->paidAtCounter($bill)->plus($allocated),
            $credited ?? $this->creditedAgainst($bill),
        );
    }

    /**
     * What has been credited back off this bill by returns — M18.
     *
     * Not a payment, and kept apart from one for that reason: nobody handed over
     * any money, the goods came back. It reduces what is *owed* on the invoice
     * all the same, and a bill whose whole contents were returned is settled
     * without a rupee having moved.
     *
     * Summed over the credit notes' own totals rather than derived from the
     * ledger, because the ledger records the credit against the party's control
     * account and not against a named invoice — the same reason
     * `transaction_allocations` exists.
     */
    private function creditedAgainst(Transaction $bill): Money
    {
        return Money::of(
            $bill->returns()->where('status', TransactionStatus::Posted->value)->sum('total') ?: 0
        );
    }

    /**
     * The same answer for a whole page of bills, in one query rather than one
     * per row — what a listing needs and what the per-row version would make
     * quadratic.
     *
     * @param  Collection<int, Transaction>|array<int, Transaction>  $bills
     * @return array<int, array{total: string, paid: string, due: string, status: PaymentStatus, due_date: string|null}|null>
     */
    public function settlementsFor(Collection|array $bills): array
    {
        $bills = collect($bills);

        $settleable = $bills->filter(
            fn (Transaction $bill) => $bill->type->isBillable() && $bill->isPosted()
        );

        $ids = $settleable->pluck('id')->map(fn ($id) => (int) $id)->all();

        $allocated = $this->allocations->allocatedAgainstBills($ids);
        $credited = $this->creditedAgainstBills($ids);

        $positions = [];

        foreach ($bills as $bill) {
            $id = (int) $bill->id;

            $positions[$id] = $this->settlementUsing(
                $bill,
                $allocated[$id] ?? Money::zero(),
                $credited[$id] ?? Money::zero(),
            );
        }

        return $positions;
    }

    /**
     * What returns have credited back off each of a page of bills — M18, in one
     * query rather than one per row.
     *
     * @param  array<int, int>  $billIds
     * @return array<int, Money>
     */
    private function creditedAgainstBills(array $billIds): array
    {
        $totals = array_fill_keys($billIds, Money::zero());

        if ($billIds === []) {
            return $totals;
        }

        $rows = Transaction::query()
            ->selectRaw('against_transaction_id, SUM(total) as aggregate')
            ->whereIn('against_transaction_id', $billIds)
            // Posted only. A reversed credit note has itself been cancelled, so
            // the goods are the customer's again and the invoice is owed in full.
            ->where('status', TransactionStatus::Posted->value)
            ->groupBy('against_transaction_id')
            ->pluck('aggregate', 'against_transaction_id');

        foreach ($rows as $billId => $amount) {
            $totals[(int) $billId] = Money::of((string) $amount);
        }

        return $totals;
    }

    /**
     * @return array{total: string, paid: string, due: string, status: PaymentStatus, due_date: string|null}
     */
    private function positionFrom(Transaction $bill, Money $paid, Money $credited): array
    {
        $total = $bill->totalMoney();
        // What the customer still owes: the invoice, less what they have paid,
        // less what they have brought back. A bill entirely returned is settled
        // without a rupee having moved, which is correct — and is why `credited`
        // is reported beside `paid` rather than folded into it.
        $due = $total->minus($paid)->minus($credited);
        $dueDate = $this->dueDateFor($bill);

        return [
            'total' => $total->amount(),
            'paid' => $paid->amount(),
            'credited' => $credited->amount(),
            // Floored at zero rather than reported negative. Over-allocation is
            // refused at the point of writing, so a negative here would mean the
            // data is already wrong — and a "-₹500 due" on an invoice teaches
            // nobody anything about what to do next.
            'due' => $due->isNegative() ? Money::zero()->amount() : $due->amount(),
            'status' => $this->statusOf($total, $paid->plus($credited), $dueDate),
            'due_date' => $dueDate?->toDateString(),
        ];
    }

    /**
     * Paid, partial, unpaid — or overdue, which replaces the last two once the
     * workshop's terms have run out.
     *
     * `$settled` is money paid **plus** goods credited back, because both
     * discharge the invoice: a customer who returned everything owes nothing,
     * and calling that bill unpaid would put it on somebody's chasing list.
     *
     * Compared with `compareTo` rather than by subtraction so that a bill paid to
     * the paise is Paid and one paid a paisa short is Partial. Rounding either
     * way here would be the difference between a customer being chased and not.
     */
    private function statusOf(Money $total, Money $settled, ?CarbonImmutable $dueDate): PaymentStatus
    {
        if ($settled->compareTo($total) >= 0) {
            return PaymentStatus::Paid;
        }

        if ($dueDate !== null && $dueDate->isPast()) {
            return PaymentStatus::Overdue;
        }

        return $settled->isPositive() ? PaymentStatus::Partial : PaymentStatus::Unpaid;
    }

    /**
     * Money taken on the document itself, from whichever copy of the split
     * exists.
     *
     * The loaded rows where a caller fetched them, the subquery sum where a
     * caller added one instead, and zero otherwise — which is honest here in a
     * way it would not be elsewhere, because a posted bill with no
     * `transaction_payments` rows genuinely took nothing at the counter.
     */
    private function paidAtCounter(Transaction $bill): Money
    {
        if ($bill->relationLoaded('payments')) {
            return Money::sum($bill->payments->map(fn ($payment) => $payment->amountMoney()));
        }

        if ($bill->payments_sum_amount !== null) {
            return Money::of($bill->payments_sum_amount);
        }

        return Money::of($bill->payments()->sum('amount') ?: 0);
    }

    /**
     * When this bill falls due, per the workshop's own terms. Null where it has
     * not set any — see {@see \App\Models\Tenant::dueDateFor()}.
     */
    private function dueDateFor(Transaction $bill): ?CarbonImmutable
    {
        return $this->tenantOf((int) $bill->tenant_id)?->dueDateFor($bill->date);
    }

    /**
     * The workshop, fetched once.
     *
     * Memoised because {@see settlementsFor()} answers a whole page and the
     * payment terms are one row that cannot change under it mid-request — a
     * query per bill to read the same setting is the classic listing-page
     * mistake, arrived at through a settings lookup rather than a relation.
     */
    private function tenantOf(int $tenantId): ?Tenant
    {
        return $this->tenants[$tenantId] ??= $this->tenantRepository->findById($tenantId);
    }

    /**
     * A bill's lines, with the stock movement behind each one loaded — which is
     * what makes {@see \App\Models\TransactionLine::margin()} answerable without
     * a query per line.
     *
     * @return Collection<int, \App\Models\TransactionLine>
     */
    public function linesFor(Transaction $transaction): Collection
    {
        return $this->lines->forTransaction((int) $transaction->id);
    }

    /**
     * The invoice footer: taxable value, the tax split three ways, and the total.
     *
     * Summed from the lines rather than recomputed on the total, and the two are
     * not the same thing: a bill with an 18% motor and a 12% service has no
     * single rate, and applying one to the sum would produce a figure matching no
     * line on the document.
     *
     * @return array{taxable: string, cgst: string, sgst: string, igst: string, tax: string, total: string, inter_state: bool}
     */
    public function taxSummaryFor(Transaction $transaction): array
    {
        $lines = $transaction->relationLoaded('lines') ? $transaction->lines : $this->linesFor($transaction);

        $taxable = Money::zero();
        $cgst = Money::zero();
        $sgst = Money::zero();
        $igst = Money::zero();

        foreach ($lines as $line) {
            $taxable = $taxable->plus($line->taxableMoney());
            $cgst = $cgst->plus(Money::of($line->cgst_amount));
            $sgst = $sgst->plus(Money::of($line->sgst_amount));
            $igst = $igst->plus(Money::of($line->igst_amount));
        }

        $tax = $cgst->plus($sgst)->plus($igst);

        return [
            'taxable' => $taxable->amount(),
            'cgst' => $cgst->amount(),
            'sgst' => $sgst->amount(),
            'igst' => $igst->amount(),
            'tax' => $tax->amount(),
            'total' => $taxable->plus($tax)->amount(),
            // One shape or the other, never a mixture — the whole document has
            // one place of supply.
            'inter_state' => ! $igst->isZero(),
        ];
    }

    /**
     * What the bill made: revenue less the cost of what left the shelf.
     *
     * **Null on a purchase**, deliberately. Buying something does not earn
     * anything, and reporting a "margin" of minus the whole invoice would be a
     * figure nobody could use. Null on a bill with no stock lines too — a
     * labour-only invoice has no cost of goods, and calling that a 100% margin
     * would flatter the workshop's most valuable work.
     *
     * @return array{revenue: string, cost: string, margin: string, margin_percent: string}|null
     */
    public function marginFor(Transaction $transaction): ?array
    {
        if ($transaction->type !== TransactionType::Sale) {
            return null;
        }

        $lines = $transaction->relationLoaded('lines') ? $transaction->lines : $this->linesFor($transaction);

        $revenue = Money::zero();
        $cost = Money::zero();
        $costed = false;

        foreach ($lines as $line) {
            $revenue = $revenue->plus($line->taxableMoney());

            $lineCost = $line->cost();

            if ($lineCost !== null) {
                $cost = $cost->plus($lineCost);
                $costed = true;
            }
        }

        if (! $costed) {
            return null;
        }

        $margin = $revenue->minus($cost);

        return [
            'revenue' => $revenue->amount(),
            'cost' => $cost->amount(),
            'margin' => $margin->amount(),
            'margin_percent' => $this->percentOf($margin, $revenue),
        ];
    }

    /**
     * Everything about this bill somebody should look at — and nothing that
     * should have stopped it.
     *
     * @return array<int, array{code: string, message: string, line?: int}>
     */
    public function warningsFor(Transaction $transaction): array
    {
        $lines = $transaction->relationLoaded('lines') ? $transaction->lines : $this->linesFor($transaction);
        $warnings = [];

        foreach ($lines as $line) {
            if ($transaction->type === TransactionType::Sale && $line->isBelowCost()) {
                $warnings[] = [
                    'code' => 'BILL_LINE_BELOW_COST',
                    'line' => (int) $line->line_no,
                    'message' => sprintf(
                        '%s was sold for %s but cost %s. That is a loss of %s on the line — deliberate when '.
                        'clearing old stock, and worth a second look when it is not.',
                        $line->description,
                        $line->taxableMoney()->amount(),
                        $line->cost()?->amount() ?? '0.00',
                        $line->margin()?->absolute()->amount() ?? '0.00',
                    ),
                ];
            }

            // Asked of the position *now* rather than of the movement, because
            // that is the question worth answering: the bill is posted either
            // way, and what somebody has to act on is that the shelf disagrees
            // with the books today.
            if ($line->is_stock && $line->variant !== null) {
                $position = $this->stock->positionFor($line->variant);

                if ($position->isNegative()) {
                    $warnings[] = [
                        'code' => 'STOCK_NEGATIVE',
                        'line' => (int) $line->line_no,
                        'message' => sprintf(
                            '%s is now at %s — more has been issued than received. The purchase behind it is '.
                            'probably not entered yet.',
                            $line->description,
                            $position->quantity->trimmed(),
                        ),
                    ];
                }
            }
        }

        return $warnings;
    }

    /**
     * A margin as a percentage of revenue, to two decimals.
     *
     * "0.00" against zero revenue rather than a division: a bill given away
     * entirely has no percentage, and inventing one would put an infinity on a
     * screen.
     */
    private function percentOf(Money $part, Money $whole): string
    {
        if ($whole->isZero()) {
            return '0.00';
        }

        // Integer arithmetic to two decimals of a percent: paise × 10,000 ÷ paise.
        $points = intdiv($part->minor() * 10000, $whole->minor());

        return sprintf('%s%d.%02d', $points < 0 ? '-' : '', intdiv(abs($points), 100), abs($points) % 100);
    }

    /**
     * The totals of several breakdowns — exposed so a preview can print the same
     * footer the posted bill will.
     *
     * @param  array<int, GstBreakdown>  $breakdowns
     * @return array{taxable: Money, cgst: Money, sgst: Money, igst: Money, tax: Money, total: Money}
     */
    public function totalsOf(array $breakdowns): array
    {
        return GstBreakdown::totals($breakdowns);
    }
}
