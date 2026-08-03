<?php

namespace App\Services\Accounting;

use App\Enums\BalanceSide;
use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * What a party owes, and what they are owed.
 *
 * Every figure here is a sum over `journal_entries` reached through
 * `transactions.party_id`. Nothing is stored, nothing is cached between
 * requests and nothing is incremented on posting — which is why an outstanding
 * balance cannot drift from the ledger it is supposed to summarise. There is no
 * write path in this class at all.
 *
 * ## Why a party ledger is the same rows as the control account
 *
 * `Sundry Debtors` holds the total owed by every customer; `Sundry Creditors`
 * the total owed to every supplier. A party ledger is those same entries broken
 * out by counterparty. They agree because they are one set of rows summed two
 * ways — which is the reason the sum of every party's receivable always equals
 * the Receivables control balance, without anything reconciling them.
 *
 * ## Why roles do not decide which accounts are read
 *
 * Both control accounts are read for every party, whatever roles they hold.
 * Scoping the read to the roles on the record would mean that dropping the
 * "vendor" tag from someone with an unpaid bill silently emptied that half of
 * their ledger, while the money stayed in the control account — a party
 * balance and a trial balance disagreeing, caused by an edit to a label. Roles
 * classify a party for the people using the system; they are not a filter on
 * the books.
 */
class PartyLedgerService
{
    /**
     * A position, as reported everywhere in this class.
     *
     * `receivable` and `payable` are each stated on their own normal side, so
     * both are positive in the ordinary case. `net` is signed towards the
     * workshop: positive means the party owes money, negative means the
     * workshop does. A counterparty who is both shows all three, because
     * netting a ₹40,000 receivable against a ₹38,000 payable into "₹2,000" is
     * true and useless — the two are settled separately and on different terms.
     */
    private const EMPTY_POSITION = ['receivable' => '0.00', 'payable' => '0.00', 'net' => '0.00'];

    /**
     * What has passed through the relationship since it began, as opposed to
     * what is left over. Each figure is one gross side of a control account:
     * `billed` and `received` are the debits and credits on Receivables,
     * `purchased` and `paid` those on Payables — so `billed − received` is the
     * receivable, by construction, and the two halves of the screen can never
     * disagree.
     */
    private const EMPTY_LIFETIME = [
        'billed' => '0.00', 'received' => '0.00', 'purchased' => '0.00', 'paid' => '0.00',
    ];

    public function __construct(
        private readonly JournalEntryRepositoryInterface $entries,
        private readonly ChartOfAccountService $accounts,
    ) {}

    /* ---------------------------------------------------------------------
     | The control accounts
     |-------------------------------------------------------------------- */

    /**
     * The accounts a party's position can live in, keyed by account id.
     *
     * Resolved by system key rather than by name or number, so a workshop that
     * renames "Sundry Debtors" to "Customers" changes nothing here.
     *
     * @return array<int, PartyRole>
     */
    public function controlAccounts(): array
    {
        $map = [];

        foreach (PartyRole::cases() as $role) {
            $map[$this->accounts->system($role->controlAccount())->id] = $role;
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    private function controlAccountIds(): array
    {
        return array_keys($this->controlAccounts());
    }

    /* ---------------------------------------------------------------------
     | Positions
     |-------------------------------------------------------------------- */

    /**
     * One party's outstanding position.
     *
     * @return array{receivable: Money, payable: Money, net: Money}
     */
    public function positionFor(Party $party, ?string $from = null, ?string $to = null): array
    {
        return $this->foldTotals(
            $this->entries->totalsForParty($party->id, $this->controlAccountIds(), $from, $to)
        );
    }

    /**
     * The positions of a whole page of parties, in one query.
     *
     * Returns decimal strings rather than Money because this feeds a listing
     * straight into JSON, and the caller has nothing left to compute.
     *
     * @param  Collection<int, Party>|array<int, Party>  $parties
     * @return array<int, array{receivable: string, payable: string, net: string}>
     */
    public function positionsFor(Collection|array $parties, ?string $to = null): array
    {
        return array_map(
            static fn (array $summary) => $summary['outstanding'],
            $this->summariesFor($parties, $to),
        );
    }

    /**
     * A page of parties' positions *and* what they have traded to date, from
     * the one query.
     *
     * The lifetime figures are the gross sides of the same control-account
     * totals the position is netted from, so they cost nothing extra: what a
     * customer has been billed is the debits on Receivables, and what they have
     * settled is the credits. That is also why they are stated gross rather
     * than as "sales" — this reads the ledger, not the sales register, so a
     * credit note reduces `billed` exactly as it reduced the receivable, and
     * an opening balance is included because the customer genuinely owes it.
     *
     * @param  Collection<int, Party>|array<int, Party>  $parties
     * @return array<int, array{
     *     outstanding: array{receivable: string, payable: string, net: string},
     *     lifetime: array{billed: string, received: string, purchased: string, paid: string}
     * }>
     */
    public function summariesFor(Collection|array $parties, ?string $to = null): array
    {
        $ids = collect($parties)->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Every party starts at zero rather than being absent: a party with no
        // transactions has an outstanding of nought, which is an answer, and a
        // caller should never have to distinguish "nothing owed" from "not
        // fetched".
        $summaries = array_fill_keys($ids, [
            'outstanding' => self::EMPTY_POSITION,
            'lifetime' => self::EMPTY_LIFETIME,
        ]);

        if ($ids === []) {
            return $summaries;
        }

        $totals = $this->entries
            ->totalsByParty($ids, $this->controlAccountIds(), $to)
            ->groupBy('party_id');

        foreach ($totals as $partyId => $rows) {
            $folded = $this->foldTotals(collect($rows));

            $summaries[(int) $partyId] = [
                'outstanding' => [
                    'receivable' => $folded['receivable']->amount(),
                    'payable' => $folded['payable']->amount(),
                    'net' => $folded['net']->amount(),
                ],
                'lifetime' => $this->foldLifetime(collect($rows)),
            ];
        }

        return $summaries;
    }

    /* ---------------------------------------------------------------------
     | The ledger
     |-------------------------------------------------------------------- */

    /**
     * A party's statement: every entry that moved their position, in date
     * order, each carrying the running balance after it.
     *
     * The running balance is signed towards the workshop and cumulative from
     * the party's first entry ever — not from the start of the filtered period
     * — so a statement for June opens at the amount brought forward, and page 2
     * continues from page 1 instead of restarting at zero.
     *
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return array{
     *     party: Party,
     *     entries: LengthAwarePaginator<int, JournalEntry>,
     *     opening: Money,
     *     closing: Money,
     *     position: array{receivable: Money, payable: Money, net: Money},
     *     period: array{debit: Money, credit: Money}
     * }
     */
    public function forParty(Party $party, array $filters = [], int $perPage = 50): array
    {
        $accountIds = $this->controlAccountIds();
        $page = $this->entries->forParty($party->id, $accountIds, $filters, $perPage);

        /** @var JournalEntry|null $first */
        $first = $page->first();

        // Where this page starts from: everything preceding its first entry,
        // whichever page it is. An empty page has no entry to run from, so the
        // party's own closing position stands in.
        $opening = $first !== null
            ? $this->netOf($this->entries->totalsBeforeForParty(
                $party->id,
                $accountIds,
                $first->date->toDateString(),
                $first->id,
            ))
            : $this->positionFor($party, to: $filters['to'] ?? null)['net'];

        $running = $opening;

        foreach ($page as $entry) {
            // Debit-positive, matching `net`: a debit against a party is money
            // owed to the workshop, whichever control account it landed in.
            $running = $running->plus($entry->signedAgainst(BalanceSide::Debit));

            // Attached to the model rather than computed in the resource: the
            // number depends on every entry before it, which a per-row
            // serialiser cannot see.
            $entry->setAttribute('running_balance', $running->amount());
        }

        $period = $this->grossTotals(
            $this->entries->totalsForParty(
                $party->id,
                $accountIds,
                $filters['from'] ?? null,
                $filters['to'] ?? null,
            )
        );

        return [
            'party' => $party,
            'entries' => $page,
            'opening' => $opening,
            'closing' => $running,
            'position' => $this->positionFor($party, to: $filters['to'] ?? null),
            'period' => $period,
        ];
    }

    /* ---------------------------------------------------------------------
     | Folding raw totals into a position
     |-------------------------------------------------------------------- */

    /**
     * Turn per-account totals into a receivable, a payable and a net.
     *
     * @param  Collection<int, array{account_id: int, debit: string, credit: string}>  $totals
     * @return array{receivable: Money, payable: Money, net: Money}
     */
    private function foldTotals(Collection $totals): array
    {
        $roles = $this->controlAccounts();

        $receivable = Money::zero();
        $payable = Money::zero();

        foreach ($totals as $row) {
            $role = $roles[(int) $row['account_id']] ?? null;

            if ($role === null) {
                continue;
            }

            $debit = Money::of($row['debit']);
            $credit = Money::of($row['credit']);

            if ($role->controlAccount() === SystemAccount::Receivables) {
                // Debit-normal: what they owe, less what they have paid.
                $receivable = $receivable->plus($debit->minus($credit));
            } else {
                // Credit-normal: what is owed to them, less what has been paid.
                $payable = $payable->plus($credit->minus($debit));
            }
        }

        return [
            'receivable' => $receivable,
            'payable' => $payable,
            // Signed towards the workshop. Equal to the combined ledger's
            // closing running balance by construction, since both are
            // Σ(debit − credit) over the same rows.
            'net' => $receivable->minus($payable),
        ];
    }

    /**
     * The same per-account totals, kept gross instead of netted.
     *
     * `foldTotals` answers "what is left owing"; this answers "how much has
     * gone through". Both read the same rows, which is the point — a screen
     * that showed ₹1,42,500 billed and a ₹13,500 receivable is showing two
     * views of one set of entries, not two numbers that have to be reconciled.
     *
     * @param  Collection<int, array{account_id: int, debit: string, credit: string}>  $totals
     * @return array{billed: string, received: string, purchased: string, paid: string}
     */
    private function foldLifetime(Collection $totals): array
    {
        $roles = $this->controlAccounts();

        $billed = Money::zero();
        $received = Money::zero();
        $purchased = Money::zero();
        $paid = Money::zero();

        foreach ($totals as $row) {
            $role = $roles[(int) $row['account_id']] ?? null;

            if ($role === null) {
                continue;
            }

            $debit = Money::of($row['debit']);
            $credit = Money::of($row['credit']);

            if ($role->controlAccount() === SystemAccount::Receivables) {
                $billed = $billed->plus($debit);
                $received = $received->plus($credit);
            } else {
                $purchased = $purchased->plus($credit);
                $paid = $paid->plus($debit);
            }
        }

        return [
            'billed' => $billed->amount(),
            'received' => $received->amount(),
            'purchased' => $purchased->amount(),
            'paid' => $paid->amount(),
        ];
    }

    /**
     * @param  array{debit: string, credit: string}  $totals
     */
    private function netOf(array $totals): Money
    {
        return Money::of($totals['debit'])->minus(Money::of($totals['credit']));
    }

    /**
     * @param  Collection<int, array{account_id: int, debit: string, credit: string}>  $totals
     * @return array{debit: Money, credit: Money}
     */
    private function grossTotals(Collection $totals): array
    {
        return [
            'debit' => Money::sum($totals->map(fn (array $row) => Money::of($row['debit']))->all()),
            'credit' => Money::sum($totals->map(fn (array $row) => Money::of($row['credit']))->all()),
        ];
    }
}
