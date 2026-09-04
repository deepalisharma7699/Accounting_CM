<?php

namespace App\Services\Reporting\Insights;

use App\Enums\StockMovementType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\ItemVariant;
use App\Models\StockMovement;
use App\Services\Inventory\StockLedgerService;
use App\Services\Reporting\ReportPeriod;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the shelf is worth, and what is not moving — M23.
 *
 * ## Nothing here recomputes a position
 *
 * The quantities and the weighted average cost come from
 * {@see StockLedgerService}, which is the one place that decides what a variant's
 * position is (§4.3, §4.4). This class asks that service the questions the Stock
 * module does not: not "how many are there" but "how long have they been there",
 * "what is this shelf actually turning over", and "what did we write off".
 *
 * Reimplementing the roll-up here to save a call would be the exact failure
 * CLAUDE.md names — a second arithmetic for stock value, agreeing right up until
 * one of them was changed.
 *
 * ## Dead stock is the panel that pays for the module
 *
 * A workshop's cash is on its shelves, and nothing in the application has ever
 * been able to say which part of it has not moved. `stock_movements` has carried
 * the answer since M8 — the date of the last issue against each variant — and it
 * has never been asked. The threshold is a parameter rather than a constant
 * because "slow" means something different for a bearing and for a 30 HP motor.
 *
 * ## Shrinkage is invisible in the P&L, on purpose
 *
 * `StockAdjustmentTemplate` posts a write-off to COGS rather than to an account
 * of its own, and its note explains why: a separate account would report a
 * healthier gross margin than the workshop actually earns. The consequence is
 * that a shortage is indistinguishable from a sale in the statement — so it is
 * surfaced here, where the question is being asked directly, from the movements
 * rather than from the ledger.
 */
class StockInsights
{
    /**
     * How long without an issue before stock is called slow.
     *
     * Ninety days: a quarter is long enough that a seasonal part is not
     * defamed, and short enough that money sitting still is found in the year it
     * stopped moving. Reported alongside the figure so the reader knows what
     * they are being told.
     */
    public const DEAD_AFTER_DAYS = 90;

    /** How many rows each worklist carries. */
    private const TOP_N = 15;

    public function __construct(private readonly StockLedgerService $stock) {}

    /**
     * @return array<string, mixed>
     */
    public function forPeriod(ReportPeriod $period): array
    {
        $report = $this->stock->report([]);
        $totals = $this->stock->totals();

        return [
            'position' => [
                'value' => $totals['value']->amount(),
                'variants' => $report['totals']['variants'],
                'low' => $report['totals']['low'],
                'out_of_stock' => $report['totals']['out_of_stock'],
                // Apart from `low`, because they are different problems: low
                // stock is a purchasing decision, negative stock is a sale
                // recorded before the purchase that supplied it.
                'negative' => $report['totals']['negative'],
            ],
            'value_trend' => $this->valueTrend($period),
            'turnover' => $this->turnover($period),
            'dead' => $this->deadStock(),
            'reorder' => $this->reorderList($report['rows']),
            'shrinkage' => $this->shrinkage($period),
        ];
    }

    /* ---------------------------------------------------------------------
     | What the shelf has been worth
     |-------------------------------------------------------------------- */

    /**
     * Stock value over time, built forwards from the movements.
     *
     * Every movement carries the *value* it added or removed, so the running
     * total of them is the value of the shelf on any date — the same figure
     * `StockLedgerService::totals()` reports for today, arrived at the same way.
     * There is no stored history and there does not need to be.
     *
     * The opening balance is the sum of everything before the window, so a
     * six-month chart does not start at zero on a workshop that has been trading
     * for two years.
     *
     * @return array{granularity: string, buckets: array<int, array<string, string>>}
     */
    public function valueTrend(ReportPeriod $period): array
    {
        $opening = $period->from === null
            ? Money::zero()
            : Money::of(StockMovement::query()
                ->whereDate('date', '<', $period->from)
                ->sum('value') ?? 0);

        $rows = StockMovement::query()
            ->when($period->from, fn ($query, $from) => $query->whereDate('date', '>=', $from))
            ->when($period->to, fn ($query, $to) => $query->whereDate('date', '<=', $to))
            ->selectRaw('date, sum(value) as movement')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $dates = $rows->map(fn ($row) => (string) $row->date)->all();
        $granularity = TrendGranularity::forPeriod($period, $dates);

        $perBucket = [];

        foreach ($rows as $row) {
            $key = $granularity->keyFor((string) $row->date);

            $perBucket[$key] = ($perBucket[$key] ?? Money::zero())->plus(Money::of($row->movement ?? 0));
        }

        $running = $opening;
        $buckets = [];

        // A closing balance, not a movement: what the shelf was worth at the end
        // of each bucket. A chart of movements would show a workshop that bought
        // and sold evenly as a flat line at zero, which is true of the flow and
        // says nothing about the money tied up.
        foreach ($granularity->keysIn($period, $dates) as $key) {
            $running = $running->plus($perBucket[$key] ?? Money::zero());

            $buckets[] = [
                'key' => $key,
                'label' => $granularity->labelFor($key),
                'value' => $running->amount(),
            ];
        }

        return ['granularity' => $granularity->name, 'buckets' => $buckets];
    }

    /* ---------------------------------------------------------------------
     | Turnover
     |-------------------------------------------------------------------- */

    /**
     * How many times the shelf turned over in the period.
     *
     * Cost of goods issued divided by the average value held. The classic
     * measure, and the one figure that tells a workshop whether its stock is
     * working or parked: two turns a year on a ₹6,00,000 shelf is ₹3,00,000
     * doing nothing.
     *
     * Both halves come from `stock_movements` rather than one from the ledger and
     * one from here — the COGS account also carries write-offs, and a turnover
     * ratio inflated by a stock-take would be reassuring in exactly the wrong
     * circumstances.
     *
     * @return array<string, string|int|null>
     */
    public function turnover(ReportPeriod $period): array
    {
        $issued = Money::of(abs((float) $this->movementQuery($period)
            ->whereIn('transactions.type', [TransactionType::Sale->value])
            ->where('stock_movements.type', StockMovementType::Out->value)
            ->sum('stock_movements.value')));

        $opening = $period->from === null
            ? Money::zero()
            : Money::of(StockMovement::query()->whereDate('date', '<', $period->from)->sum('value') ?? 0);

        $closing = Money::of(StockMovement::query()
            ->when($period->to, fn ($query, $to) => $query->whereDate('date', '<=', $to))
            ->sum('value') ?? 0);

        $average = Money::fromMinor(intdiv($opening->minor() + $closing->minor(), 2));

        // Null rather than a number when there is nothing to divide by. A
        // workshop holding no stock has no turnover ratio, and printing "0.00"
        // would read as "your stock is not moving" when the truth is that there
        // is none.
        $ratio = $average->isZero() || ! $average->isPositive()
            ? null
            : number_format($issued->minor() / $average->minor(), 2, '.', '');

        // How long a rupee of stock sits before it is sold, which is the same
        // figure expressed the way a person thinks about it.
        $days = $this->daysIn($period);
        $holdingDays = ($ratio === null || (float) $ratio <= 0 || $days === null)
            ? null
            : (int) round($days / (float) $ratio);

        return [
            'issued_at_cost' => $issued->amount(),
            'opening_value' => $opening->amount(),
            'closing_value' => $closing->amount(),
            'average_value' => $average->amount(),
            'ratio' => $ratio,
            'holding_days' => $holdingDays,
            'period_days' => $days,
        ];
    }

    /* ---------------------------------------------------------------------
     | Dead stock
     |-------------------------------------------------------------------- */

    /**
     * Everything on the shelf that has not been issued for a season.
     *
     * The last issue per variant comes out of `stock_movements` in one grouped
     * query; the *positions* then come from {@see StockLedgerService}, which is
     * the only thing allowed to say what a variant holds and what it is worth.
     * A variant that has never been issued at all is included — that is the
     * worst case, not an absent one — and dated from its first receipt so the
     * "days" column means the same thing on every row.
     *
     * Not filtered by the period picker. Money that has not moved since March is
     * not less stuck because the reader is looking at August.
     *
     * @return array{threshold_days: int, value: string, variants: int, rows: array<int, array<string, mixed>>}
     */
    public function deadStock(int $thresholdDays = self::DEAD_AFTER_DAYS): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $cutoff = $today->subDays($thresholdDays)->toDateString();

        $activity = StockMovement::query()
            ->selectRaw(implode(', ', [
                'variant_id',
                // The last time anything left the shelf, per variant. Bound
                // rather than interpolated even though the value is an enum
                // case — a literal in a query string is a habit, and the next
                // one is user input.
                'max(case when type = ? then date else null end) as last_issue',
                'min(date) as first_seen',
            ]), [StockMovementType::Out->value])
            ->groupBy('variant_id')
            ->get()
            ->keyBy('variant_id');

        $stale = $activity->filter(
            fn ($row) => ($row->last_issue === null) || ((string) $row->last_issue < $cutoff)
        );

        if ($stale->isEmpty()) {
            return ['threshold_days' => $thresholdDays, 'value' => Money::zero()->amount(), 'variants' => 0, 'rows' => []];
        }

        $variants = ItemVariant::query()
            ->with('item:id,name,code')
            ->whereIn('id', $stale->keys()->all())
            ->get();

        $positions = $this->stock->positionsFor($variants);

        $rows = [];
        $value = Money::zero();

        foreach ($variants as $variant) {
            $position = $positions[$variant->id] ?? null;

            // Nothing on the shelf is not dead stock — it is simply a part the
            // workshop has stopped carrying, and listing it would bury the rows
            // that are actually holding money.
            if ($position === null || ! $position->quantity->isPositive()) {
                continue;
            }

            $row = $stale->get($variant->id);
            $since = $row->last_issue ?? $row->first_seen;
            $days = $since === null ? null : (int) CarbonImmutable::parse((string) $since)->diffInDays($today);

            $rows[] = [
                'variant_id' => (int) $variant->id,
                'item_id' => (int) $variant->item_id,
                'item' => $variant->item?->name,
                'label' => $variant->label ?? $variant->sku,
                'sku' => $variant->sku,
                'quantity' => $position->quantity->amount(),
                'unit_cost' => $position->averageCost()->amount(),
                'value' => $position->value->amount(),
                'last_issue' => $row->last_issue === null ? null : (string) $row->last_issue,
                'days_idle' => $days,
                // Said plainly, because "never sold" and "not sold since April"
                // are different problems: one is a buying mistake, the other is
                // a part that has gone out of use.
                'never_issued' => $row->last_issue === null,
            ];

            $value = $value->plus($position->value);
        }

        usort($rows, static fn (array $a, array $b) => bccomp($b['value'], $a['value'], 2));

        return [
            'threshold_days' => $thresholdDays,
            'value' => $value->amount(),
            'variants' => count($rows),
            'rows' => array_slice($rows, 0, self::TOP_N),
        ];
    }

    /* ---------------------------------------------------------------------
     | What to buy
     |-------------------------------------------------------------------- */

    /**
     * What is at or below its reorder level, worst first.
     *
     * Built from the rows `StockLedgerService::report()` already returned for the
     * position tiles, rather than from a second query: the status on each row is
     * the same `StockPosition::isLow()` the Stock module colours its badges with,
     * so the two screens cannot come to different conclusions about the same
     * variant.
     *
     * @param  array<int, array{variant: ItemVariant, position: \App\Services\Inventory\StockPosition}>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function reorderList(array $rows): array
    {
        $needed = [];

        foreach ($rows as $row) {
            $position = $row['position'];

            // `isLow()` is false for a variant with no reorder level set, and
            // that is right — nobody has said what low means for it. An empty
            // one still belongs here, because a part that is gone is stopping a
            // job whether or not anybody wrote a level down.
            if (! $position->isLow() && ! $position->isNegative() && ! $position->isEmpty()) {
                continue;
            }

            $variant = $row['variant'];

            $status = match (true) {
                $position->isNegative() => 'negative',
                $position->isEmpty() => 'out',
                default => 'low',
            };

            $needed[] = [
                'variant_id' => (int) $variant->id,
                'item_id' => (int) $variant->item_id,
                'item' => $variant->item?->name,
                'label' => $variant->label ?? $variant->sku,
                'sku' => $variant->sku,
                'quantity' => $position->quantity->amount(),
                'reorder_level' => $position->reorderLevel?->amount(),
                'unit_cost' => $position->averageCost()->amount(),
                'status' => $status,
                // Not a purchase order, and deliberately not called one: this is
                // the shortfall against the level somebody set, and turning it
                // into a document would be a second writer to the purchase flow.
                'shortfall' => $position->reorderLevel === null
                    ? null
                    : $position->reorderLevel->minus($position->quantity)->amount(),
            ];
        }

        usort($needed, static function (array $a, array $b) {
            // Negative and out before merely low: a part that is gone is
            // stopping a job today, where one running down is a decision for
            // this week.
            $rank = static fn (array $row) => match ($row['status']) {
                'negative' => 0,
                'out' => 1,
                default => 2,
            };

            return $rank($a) <=> $rank($b) ?: strcmp((string) $a['item'], (string) $b['item']);
        });

        return array_slice($needed, 0, self::TOP_N);
    }

    /* ---------------------------------------------------------------------
     | Shrinkage
     |-------------------------------------------------------------------- */

    /**
     * What stock-takes wrote off, and what they found.
     *
     * The two are reported apart rather than netted. A workshop that lost
     * ₹40,000 and found ₹38,000 has a counting problem, not a ₹2,000 problem,
     * and a single net figure would say the opposite.
     *
     * @return array<string, string|int>
     */
    public function shrinkage(ReportPeriod $period): array
    {
        $rows = $this->movementQuery($period)
            ->where('stock_movements.type', StockMovementType::Adjust->value)
            ->selectRaw(implode(', ', [
                'sum(case when stock_movements.value < 0 then stock_movements.value else 0 end) as written_off',
                'sum(case when stock_movements.value > 0 then stock_movements.value else 0 end) as found',
                // `lines` is reserved in MySQL (LOAD DATA ... LINES TERMINATED
                // BY), so it cannot be a bare alias.
                'count(*) as movement_lines',
                'count(distinct stock_movements.transaction_id) as stock_takes',
            ]))
            ->first();

        $writtenOff = Money::of($rows?->written_off ?? 0)->absolute();
        $found = Money::of($rows?->found ?? 0);

        return [
            'written_off' => $writtenOff->amount(),
            'found' => $found->amount(),
            'net' => $found->minus($writtenOff)->amount(),
            'lines' => (int) ($rows?->movement_lines ?? 0),
            'counts' => (int) ($rows?->stock_takes ?? 0),
        ];
    }

    /* ---------------------------------------------------------------------
     | Plumbing
     |-------------------------------------------------------------------- */

    /**
     * Movements in the period, joined to the transactions that caused them.
     *
     * Through {@see StockMovement} so the tenant scope binds — the same rule
     * that governs every query in this module.
     *
     * @return Builder<StockMovement>
     */
    private function movementQuery(ReportPeriod $period): Builder
    {
        return StockMovement::query()
            ->join('transactions', 'transactions.id', '=', 'stock_movements.transaction_id')
            /*
            | Only what still stands. Both halves of a reversal pair are dropped
            | — the document that was reversed, and the reversal that cancelled
            | it — so a stock-take that was posted and then undone reports as
            | nothing rather than as a write-off and a matching find.
            |
            | Note that {@see valueTrend()} deliberately does *not* filter this
            | way: the shelf's value has to count every movement that happened,
            | reversals included, because that is how they cancel. This filter is
            | for the flow questions — what was issued, what was written off —
            | where a cancelled event should not be reported at all.
            */
            ->where('transactions.status', TransactionStatus::Posted->value)
            ->whereNull('transactions.reverses_id')
            ->when($period->from, fn ($query, $from) => $query->whereDate('stock_movements.date', '>=', $from))
            ->when($period->to, fn ($query, $to) => $query->whereDate('stock_movements.date', '<=', $to));
    }

    private function daysIn(ReportPeriod $period): ?int
    {
        if ($period->from === null || $period->to === null) {
            return null;
        }

        return (int) CarbonImmutable::parse($period->from)->diffInDays(CarbonImmutable::parse($period->to)) + 1;
    }
}
