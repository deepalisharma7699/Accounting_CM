<?php

namespace App\Services\Reporting;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Services\Reporting\Insights\CreditInsights;
use App\Services\Reporting\Insights\PeopleInsights;
use App\Services\Reporting\Insights\SalesInsights;
use App\Services\Reporting\Insights\StockInsights;
use App\Support\Money;

/**
 * The insight module — M23. What the numbers *mean*, as opposed to what they
 * are.
 *
 * ## Why this is not the Reports module with more tabs
 *
 * M12's four reports are statements: a day book, a P&L, a GST summary, a draft
 * worklist. Each answers "what is the figure", and each is read by somebody who
 * already knows which figure they want. This module answers the question before
 * that one — "is anything wrong, and where should I look" — and every panel in
 * it is built to be read by an owner who came in with no question at all.
 *
 * They live behind one card because they are the same act at two zoom levels,
 * and because a second card called Analytics would leave somebody guessing which
 * of two screens has sales-by-month (§5.1). The statements are still served by
 * `GET /reports/*` and are not re-exposed here — a second URL for one answer is
 * a second thing to keep in step, and the second one always drifts.
 *
 * ## Every figure is a query
 *
 * There is no `insight_stats` table and there must never be one. This is the
 * module most likely to be given a nightly rollup for speed and the one where a
 * stale number would do the most damage: a workshop whose insights screen
 * disagreed with its own P&L would stop trusting both. The rule is M4's, and
 * every module since has inherited it — if something here becomes slow the
 * answer is an index, not a copy.
 *
 * ## The comparison is the product
 *
 * A number on its own is not an insight. Every headline carries the same figure
 * for the preceding window of equal length, so "₹4,20,000" becomes "₹4,20,000,
 * up 18%". {@see ReportPeriod::previous()} decides what "the window before" is,
 * and returns null where there is no honest answer — all-time and open-ended
 * ranges have no before, and a delta invented for them would be a percentage
 * that means nothing.
 *
 * ## What is withheld, and from whom
 *
 * The people section is gated on `READ:STAFF` **in addition to** `READ:LEDGER`,
 * and that is not an oversight to be tidied away by widening the card. STAFF is
 * the one grant in this application withheld for privacy rather than authority:
 * what each person earns is not something the clerk at the counter needs. A
 * caller without it gets an overview with no wage figures in it at all —
 * *absent*, not blanked, because a tile reading "—" tells somebody there is a
 * number there and invites them to go looking for it.
 */
class InsightService
{
    public function __construct(
        private readonly SalesInsights $sales,
        private readonly StockInsights $stock,
        private readonly CreditInsights $credit,
        private readonly PeopleInsights $people,
        private readonly ReportService $reports,
    ) {}

    /* ---------------------------------------------------------------------
     | The overview
     |-------------------------------------------------------------------- */

    /**
     * The headline figures, their deltas, and what needs attention.
     *
     * One assembly rather than five endpoints, for the reason the dashboard
     * gives: it is one screen opened once, and five round trips to paint it
     * would be five chances for half of it to arrive.
     *
     * @return array<string, mixed>
     */
    public function overview(ReportPeriod $period, bool $mayReadStaff = false): array
    {
        $current = $this->sales->totals($period);
        $previous = $this->previousTotals($period);

        $credit = $this->credit->forPeriod($period);
        $stock = $this->stock->forPeriod($period);

        $staff = $mayReadStaff
            ? $this->people->cost($period) + [
                'share_of_revenue' => $this->shareOfRevenue($period, $current['revenue']),
            ]
            : null;

        return [
            'period' => $period->toArray(),
            'compared_with' => $period->previous()?->toArray(),
            'headlines' => $this->headlines($current, $previous, $credit, $stock, $staff),
            'trend' => $this->sales->trend($period),
            'mix' => $this->sales->mix($period),
            'attention' => $this->attention($period, $current, $credit, $stock),
            // Stated even when it agrees, so a reader can tell "nothing to see"
            // from "we did not check" — the same judgement the GST summary's
            // reconciliation makes.
            'reconciliation' => $this->reconciliation($period, $current),
        ];
    }

    /**
     * The tiles, each with the same figure from the window before.
     *
     * A delta is `null` rather than zero where there is nothing to compare
     * against — an all-time window has no before, and a workshop's first month
     * has no previous month. The client renders no arrow at all in that case,
     * which is the honest rendering: "up 0%" would be a claim.
     *
     * @param  array<string, string|int>  $current
     * @param  array<string, string|int>|null  $previous
     * @param  array<string, mixed>  $credit
     * @param  array<string, mixed>  $stock
     * @param  array<string, string|int>|null  $staff
     * @return array<int, array<string, mixed>>
     */
    private function headlines(array $current, ?array $previous, array $credit, array $stock, ?array $staff): array
    {
        $tiles = [
            [
                'key' => 'revenue',
                'label' => 'Revenue',
                'value' => $current['revenue'],
                'note' => sprintf('%d document%s', $current['documents'], $current['documents'] === 1 ? '' : 's'),
                'delta' => $this->delta($current['revenue'], $previous['revenue'] ?? null),
                'tone' => 'neutral',
            ],
            [
                'key' => 'margin',
                'label' => 'Gross margin',
                'value' => $current['margin'],
                // The percentage is against goods revenue, never total revenue —
                // see SalesInsights::totals(). Labour has no cost of goods, and
                // including it would flatter this figure on every screen.
                'note' => $current['margin_percent'].'% on goods sold',
                'delta' => $this->delta($current['margin'], $previous['margin'] ?? null),
                // The one headline that can genuinely go either way, so the one
                // that carries a sign. A "+" on revenue or on what a customer
                // owes would be decoration claiming to be information.
                'signed' => true,
                'tone' => Money::of($current['margin'])->isNegative() ? 'bad' : 'good',
            ],
            [
                'key' => 'receivable',
                'label' => 'Owed to the workshop',
                'value' => $credit['receivable']['total'],
                'note' => sprintf(
                    '%d open invoice%s · oldest %d days',
                    $credit['receivable']['bills'],
                    $credit['receivable']['bills'] === 1 ? '' : 's',
                    $credit['receivable']['oldest_days'],
                ),
                // A position, not a flow — there is no previous-period figure
                // for what is owed *now*, and inventing one by asking what was
                // owed a month ago would need a point-in-time ageing nothing
                // stores. Absent rather than approximated.
                'delta' => null,
                'tone' => $credit['receivable']['oldest_days'] > 60 ? 'bad' : 'neutral',
            ],
            [
                'key' => 'payable',
                'label' => 'The workshop owes',
                'value' => $credit['payable']['total'],
                'note' => sprintf('%d open bill%s', $credit['payable']['bills'], $credit['payable']['bills'] === 1 ? '' : 's'),
                'delta' => null,
                'tone' => 'neutral',
            ],
            [
                'key' => 'stock_value',
                'label' => 'Stock on the shelf',
                'value' => $stock['position']['value'],
                'note' => $stock['turnover']['ratio'] === null
                    ? sprintf('%d variants', $stock['position']['variants'])
                    : sprintf('turning %s× · about %s days held', $stock['turnover']['ratio'], $stock['turnover']['holding_days'] ?? '—'),
                'delta' => null,
                'tone' => 'neutral',
            ],
            [
                'key' => 'discount',
                'label' => 'Given away in discount',
                'value' => $current['discount'],
                // The comparison that makes the figure mean something: 4%
                // average discount against a 12% margin is a third of the
                // profit. Sent as a figure rather than baked into a sentence,
                // so the client formats it the way it formats every other
                // amount on the screen.
                'compare' => $current['margin'],
                'note' => 'against a margin of',
                'delta' => $this->delta($current['discount'], $previous['discount'] ?? null),
                // Not coloured red. Discount is a decision, not a fault — what
                // it needs is to be visible, which it has never been.
                'tone' => 'neutral',
            ],
        ];

        if ($staff !== null) {
            $tiles[] = [
                'key' => 'staff_cost',
                'label' => 'Wages',
                'value' => $staff['gross'],
                'note' => $staff['share_of_revenue'] === null
                    ? sprintf('%d payslips', $staff['payslips'])
                    : $staff['share_of_revenue'].'% of revenue',
                'delta' => null,
                'tone' => 'neutral',
            ];
        }

        return $tiles;
    }

    /**
     * Wages as a share of revenue, using the revenue this screen is already
     * reporting.
     *
     * Recomputed here rather than taken from PeopleInsights, so the overview and
     * the people panel quote the same turnover for the same month. Two screens
     * disagreeing about revenue is the kind of thing that costs a report its
     * credibility for good.
     */
    private function shareOfRevenue(ReportPeriod $period, string $revenue): ?string
    {
        $total = Money::of($revenue);

        if ($total->isZero()) {
            return null;
        }

        $wages = Money::of($this->people->cost($period)['gross']);

        return number_format(($wages->minor() / $total->minor()) * 100, 2, '.', '');
    }

    /**
     * Revenue and margin for the window before this one, or null.
     *
     * @return array<string, string|int>|null
     */
    private function previousTotals(ReportPeriod $period): ?array
    {
        $previous = $period->previous();

        return $previous === null ? null : $this->sales->totals($previous);
    }

    /**
     * A percentage change, or null where there is nothing to compare against.
     *
     * A move from zero is deliberately null rather than "up 100%" or "up ∞": a
     * workshop's first invoice is not a 100% improvement on nothing, and a tile
     * saying so is a number somebody would repeat.
     *
     * @return array{direction: string, percent: string, from: string}|null
     */
    private function delta(string $current, ?string $baseline): ?array
    {
        if ($baseline === null) {
            return null;
        }

        $from = Money::of($baseline);
        $to = Money::of($current);

        if ($from->isZero()) {
            return null;
        }

        $change = (($to->minor() - $from->minor()) / abs($from->minor())) * 100;

        return [
            'direction' => match (true) {
                $change > 0.005 => 'up',
                $change < -0.005 => 'down',
                default => 'flat',
            },
            'percent' => number_format(abs($change), 1, '.', ''),
            'from' => $from->amount(),
        ];
    }

    /* ---------------------------------------------------------------------
     | What needs doing
     |-------------------------------------------------------------------- */

    /**
     * The exception feed — the part of this module somebody actually acts on.
     *
     * Rows with nothing behind them are **dropped**, never rendered as a zero.
     * A list that always shows the same eight entries, six of them saying "0",
     * is a list nobody reads; one that is empty on a good month has said
     * something. That is the judgement the dashboard's attention list already
     * makes, and this is the same idea over a period rather than over today.
     *
     * Every row carries where to go. A finding somebody cannot act on from the
     * screen it appears on is a finding they will not act on.
     *
     * **No row bakes an amount into its sentence.** A figure travels in
     * `amount`, as the decimal string every other amount in this API is, and the
     * client formats it — otherwise "80000.00" would appear beside a tile
     * reading "80,000.00" on the same screen, and the grouping a rupee figure
     * needs would be this file's business rather than the formatter's.
     *
     * @param  array<string, string|int>  $sales
     * @param  array<string, mixed>  $credit
     * @param  array<string, mixed>  $stock
     * @return array<int, array<string, mixed>>
     */
    private function attention(ReportPeriod $period, array $sales, array $credit, array $stock): array
    {
        $rows = [];

        $belowCost = $this->sales->belowCost($period);

        if ($belowCost !== []) {
            $shortfall = Money::sum(array_map(
                static fn (array $line) => Money::of($line['shortfall']),
                $belowCost,
            ));

            $rows[] = [
                'key' => 'below_cost',
                'tone' => 'bad',
                'title' => sprintf('%d line%s sold below cost', count($belowCost), count($belowCost) === 1 ? '' : 's'),
                'amount' => $shortfall->amount(),
                'detail' => 'less than what the stock was carried at.',
                'tab' => 'sales',
            ];
        }

        $old = array_values(array_filter(
            $credit['receivable']['buckets'],
            static fn (array $bucket) => $bucket['label'] === 'Over 90 days' && $bucket['count'] > 0,
        ));

        if ($old !== []) {
            $rows[] = [
                'key' => 'ageing',
                'tone' => 'bad',
                'title' => sprintf('%d invoice%s over 90 days old', $old[0]['count'], $old[0]['count'] === 1 ? '' : 's'),
                'amount' => $old[0]['amount'],
                'detail' => 'owed for a quarter or more.',
                'tab' => 'credit',
            ];
        }

        if ($stock['position']['negative'] > 0) {
            $rows[] = [
                'key' => 'negative_stock',
                'tone' => 'bad',
                // Never folded into "low stock". A low position is a purchasing
                // decision; a negative one means a sale was recorded before the
                // purchase that supplied it, which is a data problem with a
                // different fix.
                'title' => sprintf('%d variant%s showing negative stock', $stock['position']['negative'], $stock['position']['negative'] === 1 ? '' : 's'),
                'detail' => 'A sale was recorded before the purchase that supplied it.',
                'tab' => 'stock',
            ];
        }

        if ($stock['dead']['variants'] > 0) {
            $rows[] = [
                'key' => 'dead_stock',
                'tone' => 'warn',
                'title' => sprintf(
                    '%d variant%s of stock has not moved',
                    $stock['dead']['variants'],
                    $stock['dead']['variants'] === 1 ? '' : 's',
                ),
                'amount' => $stock['dead']['value'],
                'detail' => sprintf('sitting still, with no issue in %d days.', $stock['dead']['threshold_days']),
                'tab' => 'stock',
            ];
        }

        if ($stock['position']['out_of_stock'] > 0 || $stock['position']['low'] > 0) {
            $short = $stock['position']['out_of_stock'] + $stock['position']['low'];

            $rows[] = [
                'key' => 'reorder',
                'tone' => 'warn',
                'title' => sprintf('%d item%s at or below reorder level', $short, $short === 1 ? '' : 's'),
                'detail' => sprintf('%d of them are out entirely.', $stock['position']['out_of_stock']),
                'tab' => 'stock',
            ];
        }

        $drafts = Transaction::query()->drafts()->count();

        if ($drafts > 0) {
            $rows[] = [
                'key' => 'drafts',
                'tone' => 'warn',
                'title' => sprintf('%d parked draft%s', $drafts, $drafts === 1 ? '' : 's'),
                'detail' => 'Work somebody started and never authorised.',
                'tab' => 'drafts',
            ];
        }

        $shrinkage = Money::of($stock['shrinkage']['written_off']);

        if ($shrinkage->isPositive()) {
            $rows[] = [
                'key' => 'shrinkage',
                'tone' => 'warn',
                'title' => 'Written off at stock-take',
                'amount' => $shrinkage->amount(),
                // Worth saying, because the P&L cannot: a write-off posts to
                // COGS, so it is indistinguishable from a sale in the statement.
                'detail' => 'posted to cost of sales, so it does not show separately on the P&L.',
                'tab' => 'stock',
            ];
        }

        if ((int) $credit['credit_held']['parties'] > 0) {
            $rows[] = [
                'key' => 'credit_held',
                'tone' => 'info',
                'title' => sprintf(
                    '%d customer%s has paid ahead',
                    $credit['credit_held']['parties'],
                    (int) $credit['credit_held']['parties'] === 1 ? '' : 's',
                ),
                'amount' => $credit['credit_held']['amount'],
                // Not amber, and not counted as a debt. A customer who has paid
                // ahead is money the workshop is holding, and colouring it like
                // an overdue invoice would send somebody chasing them for it.
                'detail' => 'held on account, ahead of what they have been billed.',
                'tab' => 'credit',
            ];
        }

        return $rows;
    }

    /* ---------------------------------------------------------------------
     | Does it agree with the books
     |-------------------------------------------------------------------- */

    /**
     * Revenue off the bill lines against revenue in the ledger.
     *
     * This module sums `transaction_lines`, because that is the only place that
     * knows which item earned what. The P&L sums `journal_entries`, because that
     * is the statement. They agree whenever every rupee of income arrived
     * through a bill — and they cannot when somebody posts a manual journal
     * straight to Sales, which M4 deliberately allows because it is the
     * correction mechanism for everything else.
     *
     * The difference is **shown and never repaired**. This module reads; it does
     * not fix. Stating it even when it is nil is what lets a reader tell
     * "nothing to see" from "we did not check" — the same rule the GST summary
     * already follows.
     *
     * @param  array<string, string|int>  $sales
     * @return array<string, string|bool>
     */
    private function reconciliation(ReportPeriod $period, array $sales): array
    {
        $statement = $this->reports->profitAndLoss($period);

        $ledgerRevenue = $statement['totals']['revenue'] ?? Money::zero();
        $lineRevenue = Money::of($sales['revenue']);
        $difference = $ledgerRevenue->minus($lineRevenue);

        return [
            'ledger_revenue' => $ledgerRevenue->amount(),
            'document_revenue' => $lineRevenue->amount(),
            'difference' => $difference->amount(),
            'agrees' => $difference->isZero(),
        ];
    }

    /* ---------------------------------------------------------------------
     | The panels
     |-------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    public function salesPanel(ReportPeriod $period): array
    {
        return $this->sales->forPeriod($period, 'sale') + ['period' => $period->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function purchasePanel(ReportPeriod $period): array
    {
        return $this->sales->forPeriod($period, 'purchase') + ['period' => $period->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function stockPanel(ReportPeriod $period): array
    {
        return $this->stock->forPeriod($period) + ['period' => $period->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function creditPanel(ReportPeriod $period): array
    {
        return $this->credit->forPeriod($period) + ['period' => $period->toArray()];
    }

    /**
     * The people panel.
     *
     * Takes revenue as an argument rather than fetching it, so that "wages as a
     * share of revenue" is the *same* revenue figure the rest of the module
     * reports. Two screens quoting different turnover for one month is the kind
     * of disagreement that costs a report its credibility.
     *
     * @return array<string, mixed>
     */
    public function peoplePanel(ReportPeriod $period): array
    {
        $revenue = Money::of($this->sales->totals($period)['revenue']);

        return $this->people->forPeriod($period, $revenue) + ['period' => $period->toArray()];
    }

    /**
     * Whether this workshop has anything to report on at all.
     *
     * A brand-new workshop opening this module sees every panel drawn correctly
     * around no data, which reads as a broken screen rather than an empty one.
     * The client uses this to say so once, at the top, instead of eight times.
     */
    public function hasPostedAnything(): bool
    {
        return Transaction::query()
            ->where('status', TransactionStatus::Posted->value)
            ->whereIn('type', [
                TransactionType::Sale->value,
                TransactionType::Purchase->value,
                TransactionType::Expense->value,
            ])
            ->exists();
    }
}
