<?php

namespace App\Services\Reporting\Insights;

use App\Enums\PartyRole;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Services\Accounting\PartyLedgerService;
use App\Services\Reporting\ReportPeriod;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Who owes, who is owed, and for how long — M23.
 *
 * The one part of this module that answers a question nothing else in the
 * application could. `PartyLedgerService` says a customer owes ₹40,000;
 * `BillService` says invoice #212 has ₹8,000 outstanding. Neither says whether
 * that ₹40,000 is this week's trading or a debt from March, and those are
 * completely different situations that look identical on every existing screen.
 *
 * ## The ageing is not filtered by period, deliberately
 *
 * A position is not an event. The invoice from March is precisely the one this
 * report exists to surface, and dropping it because the picker says "this month"
 * would defeat the purpose — the same judgement the parked-draft worklist
 * already makes. The period picker still drives *collection efficiency* below,
 * which genuinely is about a window.
 *
 * ## Terms nobody agreed to are not a deadline
 *
 * `tenants.payment_due_days` is nullable on purpose: a counter trade settles on
 * the spot and has no terms. Where it is null, every bucket here is measured
 * from the **invoice date** and the report says so — `basis: 'invoice_date'`.
 * Silently treating "no terms" as "due immediately" would put a workshop's
 * entire ledger in the 90-day bucket and send somebody chasing customers who are
 * not late by any agreement anybody made. This is the same rule
 * {@see \App\Enums\PaymentStatus::Overdue} and the dashboard's overdue count
 * already follow.
 *
 * ## A negative position is not a small debt
 *
 * Where a customer has paid ahead their bills carry nothing outstanding, so they
 * appear in no bucket at all — the ageing is built from open documents, not from
 * party balances, and an over-payment is a balance without a document. It is
 * reported separately, as credit held, because
 * {@see \App\Services\Accounting\PartyLedgerService} treats it as a fourth state
 * and so does the UI (`components/party-position.js`). Folding it into "owed" as
 * a minus would make the total right and every row wrong.
 */
class CreditInsights
{
    /**
     * The bucket edges, in days past due.
     *
     * Thirty-day steps because that is how a workshop talks about it — "sixty
     * days out" — and four buckets because a fifth would be a column nobody
     * reads. `null` closes the last one: everything older lands there rather
     * than falling off the report.
     *
     * @var array<int, array{label: string, from: int, to: int|null}>
     */
    private const BUCKETS = [
        ['label' => 'Not yet due', 'from' => PHP_INT_MIN, 'to' => -1],
        ['label' => '0–30 days', 'from' => 0, 'to' => 30],
        ['label' => '31–60 days', 'from' => 31, 'to' => 60],
        ['label' => '61–90 days', 'from' => 61, 'to' => 90],
        ['label' => 'Over 90 days', 'from' => 91, 'to' => null],
    ];

    /** How many debtors the "who to ring" list carries. */
    private const TOP_N = 10;

    /**
     * What has been allocated *out of* a settlement, as SQL.
     *
     * The mirror of the repository's `PAID_EXPRESSION`, which asks what has been
     * allocated *to* a bill. Written here rather than shared because it is a
     * different question about the other side of the same table, and a helper
     * taking a column name would be harder to read than either.
     */
    private const ALLOCATED_FROM = "coalesce((select sum(ta.amount) from transaction_allocations ta
                                               where ta.settlement_transaction_id = transactions.id), 0)";

    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly TenantRepositoryInterface $tenants,
        private readonly PartyLedgerService $partyLedger,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forPeriod(ReportPeriod $period): array
    {
        $tenant = $this->workshop();
        $today = CarbonImmutable::now($tenant?->timezone ?? config('app.timezone', 'UTC'))->startOfDay();

        $receivable = $this->ageing(TransactionType::Sale, $tenant, $today);
        $payable = $this->ageing(TransactionType::Purchase, $tenant, $today);

        return [
            // Stated rather than assumed. A reader has to be able to tell an
            // ageing measured against agreed terms from one measured from the
            // invoice date, because they mean different things about the same
            // rows.
            'terms' => [
                'payment_due_days' => $tenant?->payment_due_days,
                'basis' => $tenant?->payment_due_days === null ? 'invoice_date' : 'due_date',
            ],
            'as_at' => $today->toDateString(),
            'receivable' => $receivable,
            'payable' => $payable,
            'net' => Money::of($receivable['total'])->minus(Money::of($payable['total']))->amount(),
            'collection' => $this->collection($period),
            'credit_held' => $this->creditHeld(),
            'unallocated' => $this->unallocatedReceipts(),
        ];
    }

    /**
     * Money received and never pointed at an invoice.
     *
     * The one figure that reconciles this panel against the Customers module,
     * and it exists because the two count different things. The ageing is built
     * from **open documents**: a bill stops being open when something is
     * allocated to it. A party's balance is built from the **ledger**: a receipt
     * reduces it the moment it posts, allocated or not.
     *
     * So a workshop that banks a cheque without saying which invoice it settles
     * has a customer whose balance is nil and an invoice that is still open, and
     * both screens are telling the truth about different questions. Allocating is
     * a deliberate act here — see {@see \App\Services\Accounting\SettlementService},
     * which will not guess which invoice somebody meant — so this is a worklist
     * rather than a fault.
     *
     * Surfaced rather than netted, for the reason every reconciliation in this
     * application is: a difference that is shown stays a decision, and one that
     * is quietly absorbed becomes a number nobody can explain six months later.
     *
     * @return array{amount: string, receipts: int}
     */
    private function unallocatedReceipts(): array
    {
        $row = Transaction::query()
            ->where('status', TransactionStatus::Posted->value)
            ->whereNull('reverses_id')
            ->whereIn('type', [TransactionType::Receipt->value, TransactionType::Payment->value])
            ->selectRaw(implode(', ', [
                'sum(transactions.total - '.self::ALLOCATED_FROM.') as unallocated',
                'sum(case when transactions.total > '.self::ALLOCATED_FROM.' then 1 else 0 end) as documents',
            ]))
            ->first();

        $amount = Money::of($row?->unallocated ?? 0);

        return [
            'amount' => $amount->isPositive() ? $amount->amount() : Money::zero()->amount(),
            'receipts' => (int) ($row?->documents ?? 0),
        ];
    }

    /* ---------------------------------------------------------------------
     | The ageing
     |-------------------------------------------------------------------- */

    /**
     * Open bills, bucketed by how far past due they are.
     *
     * The rows come from
     * {@see TransactionRepositoryInterface::outstandingBills()}, which computes
     * what is left on each bill with the *same SQL expression* the bills list's
     * `outstanding` filter uses. That sharing is the point: an ageing that
     * disagreed with the list it links to about which invoices are open would
     * discredit both, and the two would drift the first time either was touched.
     *
     * @return array<string, mixed>
     */
    private function ageing(TransactionType $type, ?Tenant $tenant, CarbonImmutable $today): array
    {
        $bills = $this->transactions->outstandingBills([$type->value]);

        $buckets = array_map(static fn (array $bucket) => [
            'label' => $bucket['label'],
            'amount' => Money::zero(),
            'count' => 0,
        ], self::BUCKETS);

        $total = Money::zero();
        $oldest = null;
        $byParty = [];

        foreach ($bills as $bill) {
            $due = Money::of($bill->due_amount ?? 0);

            if (! $due->isPositive()) {
                continue;
            }

            $overdueBy = $this->daysOverdue($bill, $tenant, $today);
            $index = $this->bucketFor($overdueBy);

            $buckets[$index]['amount'] = $buckets[$index]['amount']->plus($due);
            $buckets[$index]['count']++;

            $total = $total->plus($due);

            if ($oldest === null || $overdueBy > $oldest) {
                $oldest = $overdueBy;
            }

            $partyId = $bill->party_id === null ? 0 : (int) $bill->party_id;

            $byParty[$partyId] ??= [
                'id' => $bill->party_id === null ? null : $partyId,
                // A bill with no counterparty is possible — a cash sale over the
                // counter — and it still has to be counted, or the buckets and
                // the total would disagree with each other.
                'name' => $bill->party?->name ?? 'No counterparty',
                'amount' => Money::zero(),
                'count' => 0,
                'oldest_days' => 0,
                'oldest_date' => null,
            ];

            $byParty[$partyId]['amount'] = $byParty[$partyId]['amount']->plus($due);
            $byParty[$partyId]['count']++;

            if ($overdueBy > $byParty[$partyId]['oldest_days'] || $byParty[$partyId]['oldest_date'] === null) {
                $byParty[$partyId]['oldest_days'] = max($overdueBy, $byParty[$partyId]['oldest_days']);
                $byParty[$partyId]['oldest_date'] = $bill->date->toDateString();
            }
        }

        $parties = array_map(static fn (array $party) => [
            'id' => $party['id'],
            'name' => $party['name'],
            'amount' => $party['amount']->amount(),
            'count' => $party['count'],
            'oldest_days' => max(0, $party['oldest_days']),
            'oldest_date' => $party['oldest_date'],
        ], array_values($byParty));

        usort($parties, static fn (array $a, array $b) => bccomp($b['amount'], $a['amount'], 2));

        return [
            'total' => $total->amount(),
            'bills' => array_sum(array_column($buckets, 'count')),
            // Zero rather than null when nothing is open: "no bill is more than
            // 0 days late" is true and readable, where a blank invites the
            // reader to wonder whether the figure failed to load.
            'oldest_days' => max(0, $oldest ?? 0),
            'buckets' => array_map(fn (array $bucket) => [
                'label' => $bucket['label'],
                'amount' => $bucket['amount']->amount(),
                'count' => $bucket['count'],
                'share' => $this->percentOf($bucket['amount'], $total),
            ], $buckets),
            'parties' => array_slice($parties, 0, self::TOP_N),
        ];
    }

    /**
     * How many days past due a bill is — negative where it is not due yet.
     *
     * Measured from the workshop's terms where it has set any, and from the
     * invoice date where it has not. In the second case nothing is ever "not yet
     * due", which is the honest reading: money for goods already delivered is
     * owed from the day of the invoice unless somebody agreed otherwise.
     */
    private function daysOverdue(Transaction $bill, ?Tenant $tenant, CarbonImmutable $today): int
    {
        $due = $tenant?->dueDateFor($bill->date);

        $reference = $due ?? CarbonImmutable::instance($bill->date)->startOfDay();

        return (int) $reference->diffInDays($today, false);
    }

    private function bucketFor(int $days): int
    {
        foreach (self::BUCKETS as $index => $bucket) {
            if ($days >= $bucket['from'] && ($bucket['to'] === null || $days <= $bucket['to'])) {
                return $index;
            }
        }

        return count(self::BUCKETS) - 1;
    }

    /* ---------------------------------------------------------------------
     | Collection
     |-------------------------------------------------------------------- */

    /**
     * What was billed against what came in, for the period.
     *
     * Not a cash-flow statement and not pretending to be one: a receipt in
     * August may settle a July invoice, so the ratio is a rough measure over a
     * long window and a noisy one over a short one. It is here because the
     * *direction* of it is what matters — a workshop billing more than it
     * collects, month after month, is financing its customers, and no other
     * screen says so.
     *
     * @return array<string, string|int>
     */
    private function collection(ReportPeriod $period): array
    {
        $billed = $this->sumOf($period, [TransactionType::Sale]);
        $credited = $this->sumOf($period, [TransactionType::SalesReturn]);
        $received = $this->sumOf($period, [TransactionType::Receipt]);

        $net = $billed->minus($credited);

        return [
            'billed' => $net->amount(),
            'received' => $received->amount(),
            'efficiency' => $this->percentOf($received, $net),
            'paid_out' => $this->sumOf($period, [TransactionType::Payment])->amount(),
            'purchased' => $this->sumOf($period, [TransactionType::Purchase])
                ->minus($this->sumOf($period, [TransactionType::PurchaseReturn]))->amount(),
        ];
    }

    /**
     * @param  array<int, TransactionType>  $types
     */
    private function sumOf(ReportPeriod $period, array $types): Money
    {
        $total = Transaction::query()
            ->where('status', TransactionStatus::Posted->value)
            // Both halves of a reversal pair drop out, so a receipt entered
            // twice and reversed once does not read as double the collection.
            ->whereNull('reverses_id')
            ->whereIn('type', array_map(static fn (TransactionType $type) => $type->value, $types))
            ->when($period->from, fn ($query, $from) => $query->whereDate('date', '>=', $from))
            ->when($period->to, fn ($query, $to) => $query->whereDate('date', '<=', $to))
            ->sum('total');

        return Money::of($total ?? 0);
    }

    /* ---------------------------------------------------------------------
     | Money the workshop is holding
     |-------------------------------------------------------------------- */

    /**
     * Customers who have paid more than they have been billed.
     *
     * A fourth state, not a negative debt — see the class note. It is worth its
     * own figure because it is money the workshop is *holding*, which is a
     * liability dressed as a healthy-looking receivable total, and because the
     * customer usually knows about it before the workshop does.
     *
     * Read straight off the party ledger rather than from the bills, because an
     * over-payment is a balance with no document behind it and the ageing is
     * built from documents.
     *
     * @return array{amount: string, parties: int}
     */
    private function creditHeld(): array
    {
        $customers = Party::query()->withRole(PartyRole::Customer)->get();

        $amount = Money::zero();
        $count = 0;

        foreach ($this->partyLedger->positionsFor($customers) as $position) {
            $receivable = Money::of($position['receivable']);

            if ($receivable->isNegative()) {
                $amount = $amount->plus($receivable->absolute());
                $count++;
            }
        }

        return ['amount' => $amount->amount(), 'parties' => $count];
    }

    /* ---------------------------------------------------------------------
     | Plumbing
     |-------------------------------------------------------------------- */

    private function workshop(): ?Tenant
    {
        $id = $this->context->current();

        return $id === null ? null : $this->tenants->findById($id);
    }

    private function percentOf(Money $part, Money $whole): string
    {
        if ($whole->isZero()) {
            return '0.00';
        }

        return number_format(($part->minor() / $whole->minor()) * 100, 2, '.', '');
    }
}
