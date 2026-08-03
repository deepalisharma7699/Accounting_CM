<?php

namespace App\Services\Reporting;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Repositories\Contracts\TransactionLineRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Services\Accounting\LedgerService;
use App\Services\Accounting\Tax\GstRate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Reading the books at every zoom level — M12.
 *
 * **No report here has numbers of its own.** Every figure is a sum over
 * `journal_entries`, `stock_movements` or `transaction_lines`, computed at the
 * moment it is asked for. There is no reporting table, no nightly rollup and no
 * cache between requests, for the reason M4 gave and every module since has
 * inherited: a stored aggregate agrees with its entries right up until one of
 * them is written without the other, and nobody notices for months.
 *
 * That is also why this module needed almost no new machinery. The trial balance
 * was M4's, the stock summary was M8's, the party statement was M5's; what M12
 * adds is the three questions nothing had asked yet — what happened today, what
 * the tax comes to, and whether the workshop is making money — plus the worklist
 * of things somebody started and never finished.
 *
 * ## Why the P&L reads the chart and not a list of account names
 *
 * A workshop adds its own expense accounts, and a report built from a fixed list
 * would silently omit every one of them. Income and expense are properties of
 * {@see AccountType}, so the statement is assembled from whatever the chart
 * actually holds — and the only account singled out by name is COGS, because
 * gross margin is the one figure that needs cost of sales separated from
 * overheads, and that separation is the entire reason M10 kept an expense
 * distinct from a purchase.
 */
class ReportService
{
    /**
     * How long a parked draft may sit before it is flagged.
     *
     * A fortnight, and it is a *warning* rather than an expiry. A draft does not
     * go wrong at midnight on day fourteen — it goes wrong gradually, as the
     * weighted average cost behind it moves — and the posting engine already
     * handles the correctness half by re-composing a draft when it is finally
     * authorised, so nothing here can post a stale price. What staleness costs
     * is different and softer: a bill nobody finished is revenue the workshop
     * has not recorded, and after two weeks it is revenue somebody has
     * forgotten. Deleting it automatically would be destroying work; saying
     * nothing would be losing the sale.
     */
    public const STALE_AFTER_DAYS = 14;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly TransactionRepositoryInterface $transactions,
        private readonly TransactionLineRepositoryInterface $lines,
        private readonly ChartOfAccountRepositoryInterface $accounts,
    ) {}

    /* ---------------------------------------------------------------------
     | The day book
     |-------------------------------------------------------------------- */

    /**
     * Every voucher in a period, forwards, with its ledger lines.
     *
     * The oldest form of accounting report there is, and still the one somebody
     * reaches for when a figure looks wrong: not "what does this account hold"
     * but "what did we actually do that day". Read forwards, unlike the
     * transaction list, because that is the order the day happened in.
     *
     * @return array{
     *     transactions: LengthAwarePaginator<int, Transaction>,
     *     period: ReportPeriod,
     *     totals: array{debit: Money, credit: Money, is_balanced: bool},
     *     days: array<string, array{count: int, total: Money}>
     * }
     */
    public function dayBook(ReportPeriod $period, int $perPage = 25): array
    {
        $page = $this->transactions->dayBook($period->from, $period->to, $perPage);

        $days = [];

        foreach ($page as $transaction) {
            $day = $transaction->date->toDateString();

            $days[$day] ??= ['count' => 0, 'total' => Money::zero()];
            $days[$day]['count']++;
            $days[$day]['total'] = $days[$day]['total']->plus($transaction->totalMoney());
        }

        return [
            'transactions' => $page,
            'period' => $period,
            // Over the whole period rather than over this page, so the figure a
            // reader compares against is the one they asked for.
            'totals' => $this->ledger->totals($period->from, $period->to),
            'days' => $days,
        ];
    }

    /* ---------------------------------------------------------------------
     | Profit and loss
     |-------------------------------------------------------------------- */

    /**
     * What the workshop earned and what it cost, for a period.
     *
     * Three figures matter and they are reported separately, never netted:
     *
     *   * **gross margin** — revenue less the cost of what was sold. The number
     *     that says whether the trade itself works;
     *   * **overheads** — what it costs to be open, which is a different
     *     decision entirely;
     *   * **net** — the two together.
     *
     * Keeping cost of sales out of overheads is the whole reason M10 refused to
     * let an expense be a purchase. A workshop whose gross margin is 8% has a
     * pricing problem; one whose gross margin is 40% and still loses money has a
     * rent problem, and a statement that added the two together would say
     * neither.
     *
     * @return array{
     *     period: ReportPeriod,
     *     income: array<int, array<string, mixed>>,
     *     cost_of_sales: array<int, array<string, mixed>>,
     *     overheads: array<int, array<string, mixed>>,
     *     totals: array<string, Money>
     * }
     */
    public function profitAndLoss(ReportPeriod $period): array
    {
        $trial = $this->ledger->trialBalance($period->from, $period->to);

        $income = [];
        $costOfSales = [];
        $overheads = [];

        $revenue = Money::zero();
        $cost = Money::zero();
        $overhead = Money::zero();

        foreach ($trial['rows'] as $row) {
            $account = $row['account'];

            if (! in_array($account->type, [AccountType::Income, AccountType::Expense], true)) {
                // A balance sheet account. It carries forward rather than being
                // closed into the year's result, so it has no place here.
                continue;
            }

            // Signed against the account's own normal side, so income reads
            // positive when it was earned and an expense positive when it was
            // incurred — and a credit note or a refunded expense reads negative,
            // which is what it is.
            $amount = $row['signed_balance'];

            $entry = [
                'account' => $account,
                'amount' => $amount,
            ];

            if ($account->type === AccountType::Income) {
                $income[] = $entry;
                $revenue = $revenue->plus($amount);

                continue;
            }

            // The one account singled out by name, and the reason is gross
            // margin: cost of sales moves with what was sold, and overheads do
            // not. Everything else the workshop chose to add to its chart is an
            // overhead, which is right — an account somebody created is a cost
            // of being open until they say otherwise.
            if ($account->represents(SystemAccount::Cogs)) {
                $costOfSales[] = $entry;
                $cost = $cost->plus($amount);

                continue;
            }

            $overheads[] = $entry;
            $overhead = $overhead->plus($amount);
        }

        $gross = $revenue->minus($cost);

        return [
            'period' => $period,
            'income' => $income,
            'cost_of_sales' => $costOfSales,
            'overheads' => $overheads,
            'totals' => [
                'revenue' => $revenue,
                'cost_of_sales' => $cost,
                'gross_margin' => $gross,
                'overheads' => $overhead,
                'net' => $gross->minus($overhead),
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     | GST
     |-------------------------------------------------------------------- */

    /**
     * Output tax charged and input tax paid, rate by rate.
     *
     * Read from `transaction_lines` rather than from the ledger, and that is the
     * point of the report. Phase 1 has one GST Output account and one GST Input
     * account, so the journal knows what tax was charged but not *at what rate*
     * nor how it split into CGST, SGST and IGST — and a return is filed rate by
     * rate. M9 wrote those columns knowing this report would need them.
     *
     * The ledger balances are reported alongside as a **reconciliation**, not as
     * the source: if the sum of the document lines and the GST accounts ever
     * disagree, something reached a tax account without a bill behind it —
     * almost always a manual journal, which M4 deliberately allows because it is
     * the correction mechanism for everything else. Showing the difference is
     * how that stays a decision rather than a surprise on a return.
     *
     * @return array{
     *     period: ReportPeriod,
     *     output: array<string, mixed>,
     *     input: array<string, mixed>,
     *     net_payable: Money,
     *     reconciliation: array<string, mixed>
     * }
     */
    public function gstSummary(ReportPeriod $period): array
    {
        $rows = $this->lines->gstTotals($period->from, $period->to);

        $output = $this->foldGst($rows, TransactionType::Sale);
        $input = $this->foldGst($rows, TransactionType::Purchase);

        $ledgerOutput = $this->balanceOfSystemAccount(SystemAccount::GstOutput, $period);
        $ledgerInput = $this->balanceOfSystemAccount(SystemAccount::GstInput, $period);

        return [
            'period' => $period,
            'output' => $output,
            'input' => $input,
            // What would be paid over, on these documents alone. Signed: a
            // workshop that bought more than it sold this month is in credit,
            // which is a real position and not an error.
            'net_payable' => $output['tax']->minus($input['tax']),
            'reconciliation' => [
                'ledger_output' => $ledgerOutput,
                'ledger_input' => $ledgerInput,
                // Non-zero means tax reached an account without a bill line
                // behind it. Reported rather than hidden, and never corrected
                // here — this report reads, it does not repair.
                'output_difference' => $ledgerOutput->minus($output['tax']),
                'input_difference' => $ledgerInput->minus($input['tax']),
                'agrees' => $ledgerOutput->equals($output['tax']) && $ledgerInput->equals($input['tax']),
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{rates: array<int, array<string, mixed>>, taxable: Money, cgst: Money, sgst: Money, igst: Money, tax: Money}
     */
    private function foldGst(Collection $rows, TransactionType $type): array
    {
        $rates = [];

        $taxable = Money::zero();
        $cgst = Money::zero();
        $sgst = Money::zero();
        $igst = Money::zero();

        foreach ($rows as $row) {
            if ($row['type'] !== $type->value) {
                continue;
            }

            $rowTaxable = Money::of($row['taxable']);
            $rowCgst = Money::of($row['cgst']);
            $rowSgst = Money::of($row['sgst']);
            $rowIgst = Money::of($row['igst']);

            $taxable = $taxable->plus($rowTaxable);
            $cgst = $cgst->plus($rowCgst);
            $sgst = $sgst->plus($rowSgst);
            $igst = $igst->plus($rowIgst);

            $rates[] = [
                'rate' => GstRate::of($row['gst_rate'])->percent(),
                'taxable' => $rowTaxable,
                'cgst' => $rowCgst,
                'sgst' => $rowSgst,
                'igst' => $rowIgst,
                'tax' => $rowCgst->plus($rowSgst)->plus($rowIgst),
                'lines' => $row['lines'],
            ];
        }

        return [
            'rates' => $rates,
            'taxable' => $taxable,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'tax' => $cgst->plus($sgst)->plus($igst),
        ];
    }

    private function balanceOfSystemAccount(SystemAccount $key, ReportPeriod $period): Money
    {
        $account = $this->accounts->findBySystemKey($key);

        return $account === null
            ? Money::zero()
            : $this->ledger->balanceFor($account, $period->from, $period->to);
    }

    /* ---------------------------------------------------------------------
     | The worklist
     |-------------------------------------------------------------------- */

    /**
     * Everything somebody started and never authorised.
     *
     * Unfiltered by period, deliberately: a draft is outstanding work rather
     * than an event, and one from three months ago is precisely the one that
     * needs looking at — hiding it because the date picker says "this month"
     * would defeat the purpose of a worklist.
     *
     * Each row carries its age and whether it has gone stale. See
     * {@see STALE_AFTER_DAYS} for why that is a warning and not an expiry.
     *
     * @return array{
     *     transactions: LengthAwarePaginator<int, Transaction>,
     *     rows: array<int, array<string, mixed>>,
     *     totals: array{count: int, stale: int, value: Money}
     * }
     */
    public function draftWorklist(int $perPage = 25): array
    {
        $page = $this->transactions->drafts($perPage);
        $today = CarbonImmutable::now()->startOfDay();

        $rows = [];
        $stale = 0;
        $value = Money::zero();

        foreach ($page as $draft) {
            $age = (int) $draft->date->startOfDay()->diffInDays($today, absolute: true);
            $isStale = $age >= self::STALE_AFTER_DAYS;

            if ($isStale) {
                $stale++;
            }

            $value = $value->plus($draft->totalMoney());

            $rows[] = [
                'transaction' => $draft,
                'age_in_days' => $age,
                'is_stale' => $isStale,
                // Named rather than left to the client to work out, so the
                // reason a row is flagged travels with it — and so a change to
                // what "stale" means changes one place.
                'reason' => $isStale
                    ? sprintf(
                        'Parked %d days. It will be re-priced and re-costed when it posts, so the total below '.
                        'is not what it will be worth.',
                        $age,
                    )
                    : null,
            ];
        }

        return [
            'transactions' => $page,
            'rows' => $rows,
            'totals' => [
                'count' => $page->total(),
                'stale' => $stale,
                // The value of what is on this page, and worth saying so: it is
                // the least reliable figure in the module, because a draft of a
                // derived type is re-priced the moment it posts.
                'value' => $value,
            ],
        ];
    }
}
