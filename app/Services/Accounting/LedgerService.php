<?php

namespace App\Services\Accounting;

use App\Enums\BalanceSide;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Reading the books.
 *
 * Everything here is a query over `journal_entries`. There is no stored balance
 * anywhere in the product — not on an account, not on a party, not in a report
 * table — because a stored aggregate and its entries drift apart the first time
 * one of them is written without the other, and nobody notices until a
 * reconciliation months later.
 *
 * Nothing in this class writes. That is {@see PostingEngine}'s job alone.
 */
class LedgerService
{
    public function __construct(
        private readonly JournalEntryRepositoryInterface $entries,
        private readonly ChartOfAccountRepositoryInterface $accounts,
    ) {}

    /* ---------------------------------------------------------------------
     | One account
     |-------------------------------------------------------------------- */

    /**
     * An account's ledger: its entries in date order, each carrying the running
     * balance after it.
     *
     * The running balance is cumulative from the first entry ever posted, not
     * from the start of the filtered period — which is why a date-filtered view
     * still opens at the right number instead of at zero.
     *
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return array{
     *     account: ChartOfAccount,
     *     entries: LengthAwarePaginator<int, JournalEntry>,
     *     opening: Money,
     *     closing: Money,
     *     period: array{debit: Money, credit: Money},
     *     normal_balance: BalanceSide
     * }
     */
    public function forAccount(ChartOfAccount $account, array $filters = [], int $perPage = 50): array
    {
        $normal = $account->normalBalance();
        $page = $this->entries->forAccount($account->id, $filters, $perPage);

        /** @var JournalEntry|null $first */
        $first = $page->first();

        // Where this page starts from: everything that precedes its first
        // entry, whatever page it is. For an empty page there is nothing to run
        // a balance from, so the account's own closing position is used.
        $opening = $first !== null
            ? $this->signed($this->entries->totalsBefore($account->id, $first->date->toDateString(), $first->id), $normal)
            : $this->balanceFor($account, to: $filters['to'] ?? null);

        $running = $opening;

        foreach ($page as $entry) {
            $running = $running->plus($entry->signedAgainst($normal));

            // Attached to the model rather than computed in the resource: the
            // number depends on every entry before it, which a per-row
            // serialiser cannot see.
            $entry->setAttribute('running_balance', $running->amount());
        }

        $period = $this->entries->totalsForAccount(
            $account->id,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );

        return [
            'account' => $account,
            'entries' => $page,
            'opening' => $opening,
            'closing' => $running,
            'period' => [
                'debit' => Money::of($period['debit']),
                'credit' => Money::of($period['credit']),
            ],
            'normal_balance' => $normal,
        ];
    }

    /**
     * An account's balance, on its normal side: positive when the account
     * carries what its type says it should, negative when it is the other way
     * round — an overdrawn bank account, a customer in credit.
     */
    public function balanceFor(ChartOfAccount $account, ?string $from = null, ?string $to = null): Money
    {
        return $this->signed(
            $this->entries->totalsForAccount($account->id, $from, $to),
            $account->normalBalance(),
        );
    }

    /* ---------------------------------------------------------------------
     | The whole ledger
     |-------------------------------------------------------------------- */

    /**
     * The trial balance: every account that has been posted to, with its
     * debits, its credits and where its net balance sits.
     *
     * Two things must be equal, and both are reported. Gross movements — total
     * debits against total credits — prove that no entry was written in
     * isolation. Net balances prove the same thing after each account has been
     * collapsed to one side, which is the form a printed trial balance takes.
     *
     * An untouched workshop returns no rows and reconciles at 0 = 0, which is
     * correct rather than empty.
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     totals: array{debit: Money, credit: Money},
     *     balances: array{debit: Money, credit: Money},
     *     is_balanced: bool,
     *     difference: Money
     * }
     */
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        $totalsByAccount = $this->entries->totalsByAccount($from, $to)->keyBy('account_id');

        // The whole chart in one query, so the rows can be named and typed
        // without a lookup per account.
        $accounts = $this->accounts->all()->keyBy('id');

        $rows = [];

        $grossDebit = Money::zero();
        $grossCredit = Money::zero();
        $netDebit = Money::zero();
        $netCredit = Money::zero();

        foreach ($accounts as $account) {
            $totals = $totalsByAccount->get($account->id);

            if ($totals === null) {
                continue;
            }

            $debit = Money::of($totals['debit']);
            $credit = Money::of($totals['credit']);
            $difference = $debit->minus($credit);

            $grossDebit = $grossDebit->plus($debit);
            $grossCredit = $grossCredit->plus($credit);

            // A net position sits on whichever side it fell out on — which is
            // usually, but not always, the account's normal side.
            $side = $difference->isNegative() ? BalanceSide::Credit : BalanceSide::Debit;
            $balance = $difference->absolute();

            if ($difference->isZero()) {
                // Fully settled: it belongs in neither column, and forcing it
                // into one would put a zero where a reader expects a number.
                $side = $account->normalBalance();
            } elseif ($side === BalanceSide::Debit) {
                $netDebit = $netDebit->plus($balance);
            } else {
                $netCredit = $netCredit->plus($balance);
            }

            $rows[] = [
                'account' => $account,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
                'balance_side' => $side,
                // Signed against the account's own normal side, for callers
                // that want "how much Cash is there" rather than a column.
                'signed_balance' => $account->normalBalance() === BalanceSide::Debit
                    ? $difference
                    : $difference->negated(),
            ];
        }

        return [
            'rows' => $rows,
            'totals' => ['debit' => $grossDebit, 'credit' => $grossCredit],
            'balances' => ['debit' => $netDebit, 'credit' => $netCredit],
            'is_balanced' => $grossDebit->equals($grossCredit) && $netDebit->equals($netCredit),
            'difference' => $grossDebit->minus($grossCredit),
        ];
    }

    /**
     * Total debits and total credits across the entire ledger — the cheapest
     * possible statement of the one invariant that matters.
     *
     * @return array{debit: Money, credit: Money, is_balanced: bool}
     */
    public function totals(?string $from = null, ?string $to = null): array
    {
        $totals = $this->entries->totals($from, $to);

        $debit = Money::of($totals['debit']);
        $credit = Money::of($totals['credit']);

        return [
            'debit' => $debit,
            'credit' => $credit,
            'is_balanced' => $debit->equals($credit),
        ];
    }

    /**
     * @param  array{debit: string, credit: string}  $totals
     */
    private function signed(array $totals, BalanceSide $normal): Money
    {
        $debit = Money::of($totals['debit']);
        $credit = Money::of($totals['credit']);

        return $normal === BalanceSide::Debit
            ? $debit->minus($credit)
            : $credit->minus($debit);
    }
}
