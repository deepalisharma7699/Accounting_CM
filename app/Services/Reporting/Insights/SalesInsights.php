<?php

namespace App\Services\Reporting\Insights;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Reporting\ReportPeriod;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the workshop sold, and what it kept — M23.
 *
 * ## Nothing here is stored
 *
 * Every figure is a sum over `transaction_lines` joined to the `stock_movements`
 * that costed them, computed when it is asked for. That is the rule M4, M8 and
 * M12 already hold to, and this is the module most likely to be handed a rollup
 * table for speed — which is exactly why it must not have one. A workshop whose
 * insights screen disagreed with its own P&L would stop trusting both.
 *
 * ## Why the lines and not the ledger
 *
 * The P&L reads `journal_entries`, and it is right to: that is the statement.
 * But the ledger has one Sales account and one COGS account, so it cannot say
 * *which* items earned the margin, *who* bought them, or how much was given away
 * in discount. Those questions live on the document lines, and M9 wrote the
 * columns knowing something would eventually ask.
 *
 * The two agree because both are sums over the same posting. Where they cannot —
 * a manual journal straight to Sales has no bill line behind it — the difference
 * belongs to the P&L, and {@see \App\Services\Reporting\InsightService} says so
 * rather than quietly reconciling itself.
 *
 * ## Returns are subtracted, never hidden
 *
 * A credit note's lines are positive numbers on a document that means the
 * opposite, so the sign is applied here, once. Revenue is net of returns because
 * that is what the workshop actually earned — but gross and returns are both
 * reported beside it, so a month that looks quiet because half of it came back
 * says which of the two happened.
 *
 * ## Tax is not revenue
 *
 * Every figure is `taxable_value`, never `line_total`. GST is collected on the
 * department's behalf, and counting it would flatter revenue by the rate and the
 * margin percentage by more.
 */
class SalesInsights
{
    /**
     * How many rows a "top" list carries.
     *
     * Ten, because this is a screen somebody scans rather than a report they
     * work through, and the eleventh customer has never once changed a decision.
     * The full list is the Customers module, one click away.
     */
    private const TOP_N = 10;

    /**
     * The pair of document types a direction is about.
     *
     * Sales and Purchase are the same arithmetic mirrored, exactly as the two
     * modules are, so this class answers for both rather than being copied with
     * `sale` swapped for `purchase` (§4.4, §5.1). What differs is only which
     * pair of types is summed and whose name is on them.
     *
     * @return array{0: TransactionType, 1: TransactionType}
     */
    public static function documentsFor(string $direction): array
    {
        return $direction === 'purchase'
            ? [TransactionType::Purchase, TransactionType::PurchaseReturn]
            : [TransactionType::Sale, TransactionType::SalesReturn];
    }

    /**
     * The whole panel for a period.
     *
     * @return array<string, mixed>
     */
    public function forPeriod(ReportPeriod $period, string $direction = 'sale'): array
    {
        return [
            'totals' => $this->totals($period, $direction),
            'trend' => $this->trend($period, $direction),
            'mix' => $this->mix($period, $direction),
            'top_parties' => $this->topParties($period, $direction),
            'top_items' => $this->topItems($period, $direction),
            'below_cost' => $direction === 'sale' ? $this->belowCost($period) : [],
        ];
    }

    /* ---------------------------------------------------------------------
     | Totals
     |-------------------------------------------------------------------- */

    /**
     * Revenue, cost, margin and what was given away, for one window.
     *
     * Also the entry point the overview uses for the *previous* window, which is
     * why it is a method of its own rather than a slice of {@see forPeriod()}: a
     * comparison must not pay for a top-ten list nobody is going to see.
     *
     * @return array<string, string|int>
     */
    public function totals(ReportPeriod $period, string $direction = 'sale'): array
    {
        [$document, $return] = self::documentsFor($direction);

        $rows = $this->lineQuery($period, $direction)
            ->selectRaw(implode(', ', [
                'transactions.type as doc_type',
                'sum(transaction_lines.taxable_value) as taxable',
                'sum(transaction_lines.discount_amount) as discount',
                'sum(transaction_lines.cgst_amount + transaction_lines.sgst_amount + transaction_lines.igst_amount) as tax',
                // COALESCE over the LEFT JOIN: a labour line has no stock
                // movement and therefore no cost, which is not a cost of zero.
                // See costed_taxable below for why both are summed.
                'sum(coalesce(abs(stock_movements.value), 0)) as cost',
                'sum(case when stock_movements.id is null then 0 else transaction_lines.taxable_value end) as costed_taxable',
            ]))
            ->groupBy('transactions.type')
            ->get()
            ->keyBy('doc_type');

        $gross = $this->money($rows, $document->value, 'taxable');
        $returned = $this->money($rows, $return->value, 'taxable');
        $revenue = $gross->minus($returned);

        $cost = $this->money($rows, $document->value, 'cost')
            ->minus($this->money($rows, $return->value, 'cost'));

        /*
        | The revenue a margin can honestly be taken against.
        |
        | A labour-only invoice has no cost of goods — an hour is produced at the
        | moment it is sold — so including it would report the whole of it as
        | margin and flatter the percentage on every screen it appears on. This
        | is the same judgement TransactionLine::margin() already makes per line,
        | applied to the aggregate: the margin *amount* is real and is reported
        | in full, and the *percentage* is taken against goods revenue alone.
        */
        $costedRevenue = $this->money($rows, $document->value, 'costed_taxable')
            ->minus($this->money($rows, $return->value, 'costed_taxable'));

        $margin = $costedRevenue->minus($cost);
        $documents = $this->documentCounts($period, $direction);

        return [
            'revenue' => $revenue->amount(),
            'gross_revenue' => $gross->amount(),
            'returns' => $returned->amount(),
            'cost' => $cost->amount(),
            'margin' => $margin->amount(),
            'margin_percent' => $this->percentOf($margin, $costedRevenue),
            'costed_revenue' => $costedRevenue->amount(),
            'tax' => $this->money($rows, $document->value, 'tax')
                ->minus($this->money($rows, $return->value, 'tax'))->amount(),
            // What the counter gave away. Nowhere else in the application adds
            // this up, and it is routinely the largest number an owner has never
            // seen: a 4% average discount against a 12% margin is a third of the
            // profit, one line at a time.
            'discount' => $this->money($rows, $document->value, 'discount')
                ->minus($this->money($rows, $return->value, 'discount'))->amount(),
            'documents' => $documents['documents'],
            'returns_count' => $documents['returns'],
            'average_document' => $documents['documents'] > 0
                ? Money::fromMinor(intdiv($gross->minor(), $documents['documents']))->amount()
                : Money::zero()->amount(),
        ];
    }

    /**
     * How many documents, and how many of them came back.
     *
     * Counted on `transactions` rather than derived from the line query, because
     * a document with no lines — possible on a credit note that reverses a whole
     * invoice — would otherwise not be counted at all.
     *
     * @return array{documents: int, returns: int}
     */
    private function documentCounts(ReportPeriod $period, string $direction): array
    {
        [$document, $return] = self::documentsFor($direction);

        $counts = $this->documentQuery($period, $direction)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        return [
            'documents' => (int) ($counts[$document->value] ?? 0),
            'returns' => (int) ($counts[$return->value] ?? 0),
        ];
    }

    /* ---------------------------------------------------------------------
     | The trend
     |-------------------------------------------------------------------- */

    /**
     * Revenue and margin over time, bucketed to fit the window.
     *
     * Grouped by bare date in SQL and folded into buckets in PHP — see
     * {@see TrendGranularity} for why that is not a `DATE_FORMAT`.
     *
     * @return array{granularity: string, buckets: array<int, array<string, string>>}
     */
    public function trend(ReportPeriod $period, string $direction = 'sale'): array
    {
        [$document] = self::documentsFor($direction);

        $rows = $this->lineQuery($period, $direction)
            ->selectRaw(implode(', ', [
                'transactions.date as day',
                'transactions.type as doc_type',
                'sum(transaction_lines.taxable_value) as taxable',
                'sum(coalesce(abs(stock_movements.value), 0)) as cost',
                'sum(case when stock_movements.id is null then 0 else transaction_lines.taxable_value end) as costed_taxable',
            ]))
            ->groupBy('transactions.date', 'transactions.type')
            ->orderBy('transactions.date')
            ->get();

        $dates = $rows->map(fn ($row) => (string) $row->day)->all();
        $granularity = TrendGranularity::forPeriod($period, $dates);
        $buckets = [];

        foreach ($rows as $row) {
            $key = $granularity->keyFor((string) $row->day);

            $buckets[$key] ??= [
                'revenue' => Money::zero(),
                'cost' => Money::zero(),
                'costed' => Money::zero(),
            ];

            // A return is the same arithmetic with the sign flipped, which is
            // the whole reason it is applied here rather than left to whoever
            // reads the numbers.
            $positive = (string) $row->doc_type === $document->value;

            foreach ([['revenue', 'taxable'], ['cost', 'cost'], ['costed', 'costed_taxable']] as [$slot, $column]) {
                $amount = Money::of($row->{$column} ?? 0);

                $buckets[$key][$slot] = $positive
                    ? $buckets[$key][$slot]->plus($amount)
                    : $buckets[$key][$slot]->minus($amount);
            }
        }

        return [
            'granularity' => $granularity->name,
            'buckets' => array_map(function (string $key) use ($buckets, $granularity) {
                $revenue = $buckets[$key]['revenue'] ?? Money::zero();
                $cost = $buckets[$key]['cost'] ?? Money::zero();
                $costed = $buckets[$key]['costed'] ?? Money::zero();
                $margin = $costed->minus($cost);

                return [
                    'key' => $key,
                    'label' => $granularity->labelFor($key),
                    'revenue' => $revenue->amount(),
                    'cost' => $cost->amount(),
                    'margin' => $margin->amount(),
                    'margin_percent' => $this->percentOf($margin, $costed),
                ];
            }, $granularity->keysIn($period, $dates)),
        ];
    }

    /* ---------------------------------------------------------------------
     | Goods and labour
     |-------------------------------------------------------------------- */

    /**
     * Parts sold against work done.
     *
     * The split a rewinding shop actually cares about, and the one the ledger
     * cannot make: a motor sold over the counter and a motor rewound are very
     * different businesses sharing one document type. It is read off
     * `transaction_lines.is_stock`, which is the line's own record of whether it
     * moved something off a shelf.
     *
     * Each side carries its own margin, because the two are not comparable:
     * goods carry a cost and labour does not, so a shop whose mix moves towards
     * service shows a rising margin percentage that is a change in *what* it
     * sells rather than in how well it sells it.
     *
     * @return array<int, array<string, string>>
     */
    public function mix(ReportPeriod $period, string $direction = 'sale'): array
    {
        [$document] = self::documentsFor($direction);

        $rows = $this->lineQuery($period, $direction)
            ->selectRaw(implode(', ', [
                'transaction_lines.is_stock as is_stock',
                'transactions.type as doc_type',
                'sum(transaction_lines.taxable_value) as taxable',
                'sum(coalesce(abs(stock_movements.value), 0)) as cost',
            ]))
            ->groupBy('transaction_lines.is_stock', 'transactions.type')
            ->get();

        $sides = [
            ['key' => 'goods', 'label' => 'Parts and materials', 'stock' => true],
            ['key' => 'service', 'label' => 'Labour and service', 'stock' => false],
        ];

        $totals = [];
        $overall = Money::zero();

        foreach ($sides as $side) {
            $revenue = Money::zero();
            $cost = Money::zero();

            foreach ($rows as $row) {
                if ((bool) $row->is_stock !== $side['stock']) {
                    continue;
                }

                $positive = (string) $row->doc_type === $document->value;
                $taxable = Money::of($row->taxable ?? 0);
                $rowCost = Money::of($row->cost ?? 0);

                $revenue = $positive ? $revenue->plus($taxable) : $revenue->minus($taxable);
                $cost = $positive ? $cost->plus($rowCost) : $cost->minus($rowCost);
            }

            $totals[$side['key']] = ['revenue' => $revenue, 'cost' => $cost];
            $overall = $overall->plus($revenue);
        }

        return array_map(function (array $side) use ($totals, $overall) {
            $entry = $totals[$side['key']];
            $margin = $entry['revenue']->minus($entry['cost']);

            return [
                'key' => $side['key'],
                'label' => $side['label'],
                'revenue' => $entry['revenue']->amount(),
                'cost' => $entry['cost']->amount(),
                'margin' => $margin->amount(),
                'margin_percent' => $this->percentOf($margin, $entry['revenue']),
                'share' => $this->percentOf($entry['revenue'], $overall),
            ];
        }, $sides);
    }

    /* ---------------------------------------------------------------------
     | Who, and what
     |-------------------------------------------------------------------- */

    /**
     * The counterparties who account for the most of it.
     *
     * Each row carries its **share of the period's revenue**, which is the part
     * that turns a list into a warning: a workshop taking 40% of its turnover
     * from one customer has a concentration problem it will discover in the
     * month that customer goes elsewhere. The figure has nowhere else to live —
     * the Customers module lists who owes what, not who matters.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topParties(ReportPeriod $period, string $direction = 'sale'): array
    {
        [$document] = self::documentsFor($direction);

        $rows = $this->lineQuery($period, $direction)
            ->join('parties', 'parties.id', '=', 'transactions.party_id')
            ->selectRaw(implode(', ', [
                'parties.id as group_id',
                'parties.name as group_name',
                'transactions.type as doc_type',
                'sum(transaction_lines.taxable_value) as taxable',
                'sum(coalesce(abs(stock_movements.value), 0)) as cost',
                'sum(case when stock_movements.id is null then 0 else transaction_lines.taxable_value end) as costed_taxable',
                'count(distinct transactions.id) as documents',
            ]))
            ->groupBy('parties.id', 'parties.name', 'transactions.type')
            ->get();

        return $this->rankBy($rows, $document);
    }

    /**
     * The items that earn the most, and the ones that only look like they do.
     *
     * Ranked by **margin**, not by revenue, and that is the whole point of the
     * panel: the part with the largest turnover is very often not the part
     * paying the rent, and no other screen in the application can tell the two
     * apart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topItems(ReportPeriod $period, string $direction = 'sale'): array
    {
        [$document] = self::documentsFor($direction);

        $rows = $this->lineQuery($period, $direction)
            ->join('items', 'items.id', '=', 'transaction_lines.item_id')
            ->selectRaw(implode(', ', [
                'items.id as group_id',
                'items.name as group_name',
                'transactions.type as doc_type',
                'sum(transaction_lines.taxable_value) as taxable',
                'sum(coalesce(abs(stock_movements.value), 0)) as cost',
                'sum(case when stock_movements.id is null then 0 else transaction_lines.taxable_value end) as costed_taxable',
                'sum(transaction_lines.quantity) as quantity',
                'count(distinct transactions.id) as documents',
            ]))
            ->groupBy('items.id', 'items.name', 'transactions.type')
            ->get();

        return $this->rankBy($rows, $document, withQuantity: true);
    }

    /**
     * Fold the document and return rows for one grouping into a ranked list.
     *
     * Shared by the two "top" panels because the arithmetic is identical and
     * only the grouping differs — a second copy would be a second place for the
     * return sign to be got wrong.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rankBy(Collection $rows, TransactionType $document, bool $withQuantity = false): array
    {
        $grouped = [];
        $total = Money::zero();

        foreach ($rows as $row) {
            $key = (int) $row->group_id;
            $positive = (string) $row->doc_type === $document->value;

            $grouped[$key] ??= [
                'id' => $key,
                'name' => (string) $row->group_name,
                'revenue' => Money::zero(),
                'cost' => Money::zero(),
                'costed' => Money::zero(),
                'quantity' => 0.0,
                'documents' => 0,
            ];

            foreach ([['revenue', 'taxable'], ['cost', 'cost'], ['costed', 'costed_taxable']] as [$slot, $column]) {
                $amount = Money::of($row->{$column} ?? 0);

                $grouped[$key][$slot] = $positive
                    ? $grouped[$key][$slot]->plus($amount)
                    : $grouped[$key][$slot]->minus($amount);
            }

            $grouped[$key]['quantity'] += ($positive ? 1 : -1) * (float) ($row->quantity ?? 0);
            // Returns are not counted as documents: a credit note is a
            // correction to a sale, not a second visit.
            $grouped[$key]['documents'] += $positive ? (int) $row->documents : 0;

            $taxable = Money::of($row->taxable ?? 0);
            $total = $positive ? $total->plus($taxable) : $total->minus($taxable);
        }

        $ranked = array_map(function (array $entry) use ($total, $withQuantity) {
            $margin = $entry['costed']->minus($entry['cost']);

            $row = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'revenue' => $entry['revenue']->amount(),
                'cost' => $entry['cost']->amount(),
                'margin' => $margin->amount(),
                'margin_percent' => $this->percentOf($margin, $entry['costed']),
                'share' => $this->percentOf($entry['revenue'], $total),
                'documents' => $entry['documents'],
            ];

            if ($withQuantity) {
                $row['quantity'] = rtrim(rtrim(number_format($entry['quantity'], 3, '.', ''), '0'), '.') ?: '0';
            }

            return $row;
        }, array_values($grouped));

        // By margin, then by revenue as the tiebreak — bccomp rather than a
        // float subtraction, for the reason every amount in this application is
        // a decimal string in the first place.
        usort($ranked, static fn (array $a, array $b) => bccomp($b['margin'], $a['margin'], 2)
            ?: bccomp($b['revenue'], $a['revenue'], 2));

        return array_slice($ranked, 0, self::TOP_N);
    }

    /* ---------------------------------------------------------------------
     | Sold for less than it cost
     |-------------------------------------------------------------------- */

    /**
     * Every line that went out below the weighted average it was carried at.
     *
     * `TransactionLine::isBelowCost()` already answers this one line at a time,
     * and {@see \App\Services\Accounting\BillService::warningsFor()} raises it on
     * the document — but only to somebody who has opened that document. Nothing
     * in the application has ever answered "how often did this happen this
     * month", which is the question that turns a warning into a pricing
     * decision.
     *
     * Sales only. A purchase arrives at whatever it cost; there is no cost for
     * it to be below.
     *
     * @return array<int, array<string, mixed>>
     */
    public function belowCost(ReportPeriod $period): array
    {
        return $this->lineQuery($period, 'sale')
            ->join('items', 'items.id', '=', 'transaction_lines.item_id')
            ->leftJoin('parties', 'parties.id', '=', 'transactions.party_id')
            ->where('transactions.type', TransactionType::Sale->value)
            // The join is a LEFT one for every other panel, because a labour
            // line has no movement and still belongs in the revenue. Here it
            // must be present: a line with no cost cannot be below it.
            ->whereNotNull('stock_movements.id')
            ->whereRaw('transaction_lines.taxable_value < abs(stock_movements.value)')
            ->selectRaw(implode(', ', [
                'transactions.id as transaction_id',
                'transactions.doc_no as doc_no',
                'transactions.date as date',
                'parties.name as party_name',
                'items.name as item_name',
                'transaction_lines.description as description',
                'transaction_lines.quantity as quantity',
                'transaction_lines.taxable_value as revenue',
                'abs(stock_movements.value) as cost',
            ]))
            // Worst first — the line that lost the most money, not the most
            // recent one. A worklist is ordered by what needs attention.
            ->orderByRaw('(abs(stock_movements.value) - transaction_lines.taxable_value) desc')
            ->limit(self::TOP_N * 2)
            ->get()
            ->map(function ($row) {
                $revenue = Money::of($row->revenue ?? 0);
                $cost = Money::of($row->cost ?? 0);

                return [
                    'transaction_id' => (int) $row->transaction_id,
                    'doc_no' => $row->doc_no,
                    'date' => (string) $row->date,
                    'party' => $row->party_name,
                    'item' => $row->item_name,
                    'description' => $row->description,
                    'quantity' => (string) $row->quantity,
                    'revenue' => $revenue->amount(),
                    'cost' => $cost->amount(),
                    'shortfall' => $cost->minus($revenue)->amount(),
                ];
            })
            ->all();
    }

    /* ---------------------------------------------------------------------
     | The shared query
     |-------------------------------------------------------------------- */

    /**
     * Every bill line in the period, with the stock movement that costed it.
     *
     * Started from {@see TransactionLine} rather than from the query builder so
     * the tenant scope applies — `TenantScope` only binds queries that go
     * through Eloquent, and a raw `DB::table()` here would read every workshop on
     * the platform. That is the single most important line in this file.
     *
     * The join to `stock_movements` is on `transaction_line_id`, which M9 added
     * for exactly this: it makes a line's cost a join rather than a second copy
     * of a figure the stock ledger already owns (§4.3, §4.4). It is a LEFT join
     * because a labour line has no movement.
     *
     * @return Builder<TransactionLine>
     */
    private function lineQuery(ReportPeriod $period, string $direction): Builder
    {
        [$document, $return] = self::documentsFor($direction);

        return TransactionLine::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_lines.transaction_id')
            ->leftJoin('stock_movements', 'stock_movements.transaction_line_id', '=', 'transaction_lines.id')
            /*
            | Only what still stands: posted, and not one half of a reversal
            | pair.
            |
            | `inTheBooks` is right for the ledger, where a reversal's entries
            | cancel the original's and both have to be present for the trial
            | balance to balance. It is wrong here. Two exclusions, and they are
            | not the same one:
            |
            |   status = posted     drops the document that *was* reversed;
            |   reverses_id is null drops the reversal that cancelled it.
            |
            | Without the second, a reversed invoice's cancelling document would
            | be counted as a fresh sale. It carries no bill lines today, so the
            | revenue would happen to come out right and the *document count*
            | would not — and a correction that ever gained lines would silently
            | double the month. The exclusion states the intent rather than
            | relying on that.
            |
            | Correcting a bill through `/revise` is unaffected: it issues a
            | *replacement*, which is a new document with no `reverses_id`, and
            | it is counted exactly once.
            */
            ->where('transactions.status', TransactionStatus::Posted->value)
            ->whereNull('transactions.reverses_id')
            ->whereIn('transactions.type', [$document->value, $return->value])
            ->when($period->from, fn ($query, $from) => $query->whereDate('transactions.date', '>=', $from))
            ->when($period->to, fn ($query, $to) => $query->whereDate('transactions.date', '<=', $to));
    }

    /**
     * @return Builder<Transaction>
     */
    private function documentQuery(ReportPeriod $period, string $direction): Builder
    {
        [$document, $return] = self::documentsFor($direction);

        return Transaction::query()
            ->where('status', TransactionStatus::Posted->value)
            // Both halves of a reversal pair drop out — see lineQuery().
            ->whereNull('reverses_id')
            ->whereIn('type', [$document->value, $return->value])
            ->when($period->from, fn ($query, $from) => $query->whereDate('date', '>=', $from))
            ->when($period->to, fn ($query, $to) => $query->whereDate('date', '<=', $to));
    }

    /**
     * @param  Collection<int|string, object>  $rows
     */
    private function money(Collection $rows, string $key, string $column): Money
    {
        return Money::of($rows->get($key)?->{$column} ?? 0);
    }

    /**
     * A percentage of a total, to two decimals, as a string.
     *
     * Zero over zero is `0.00` rather than an error or a null: a period with no
     * trade has no margin, and the honest rendering of that is a flat line
     * rather than a gap the reader has to interpret. Computed from the *minor*
     * units, so the division is over two integers and never over a float that
     * has been through a decimal string.
     */
    private function percentOf(Money $part, Money $whole): string
    {
        if ($whole->isZero()) {
            return '0.00';
        }

        return number_format(($part->minor() / $whole->minor()) * 100, 2, '.', '');
    }
}
