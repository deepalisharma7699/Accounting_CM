<?php

namespace App\Services\Reporting\Insights;

use App\Services\Reporting\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * How finely a trend is cut — M23.
 *
 * A chart of a financial year drawn one bar per day is 365 hairlines nobody can
 * read; a chart of one week drawn one bar per month is a single bar. So the
 * granularity follows the window rather than being a setting, and it is decided
 * here so that every panel that draws a trend cuts it the same way (§4.4).
 *
 * ## Why the bucketing is done in PHP
 *
 * The obvious implementation is `GROUP BY DATE_FORMAT(date, '%Y-%m')`, and it is
 * a trap: `DATE_FORMAT` is MySQL's, `strftime` is SQLite's, `to_char` is
 * Postgres's, and the application would then hold the same arithmetic in as many
 * dialects as it is ever run against. Grouping by the bare date instead returns
 * at most 366 rows for a year — small enough that folding them here costs
 * nothing and works on any engine.
 *
 * ## Empty buckets are part of the answer
 *
 * {@see keysIn()} returns every bucket the window covers, not only the ones with
 * rows behind them. A chart built from the rows alone spaces three trading days
 * evenly across the width and reads as a steady trickle, when what happened was
 * one busy Monday and a fortnight of nothing. A gap has to look like a gap.
 */
final class TrendGranularity
{
    private function __construct(
        public readonly string $name,
        private readonly int $maxBuckets,
    ) {}

    public static function day(): self
    {
        return new self('day', 62);
    }

    public static function week(): self
    {
        return new self('week', 53);
    }

    public static function month(): self
    {
        return new self('month', 36);
    }

    /**
     * Pick the cut that gives a readable number of bars.
     *
     * Roughly: up to two months reads well daily, up to two years weekly, and
     * anything longer monthly. "All time" has no length, so it is decided from
     * the dates that actually came back — a workshop three weeks old gets a
     * daily chart of its three weeks rather than one monthly bar.
     *
     * @param  array<int, string>  $observed  the dates the query returned
     */
    public static function forPeriod(ReportPeriod $period, array $observed = []): self
    {
        [$from, $to] = self::span($period, $observed);

        if ($from === null || $to === null) {
            return self::month();
        }

        $days = $from->diffInDays($to) + 1;

        return match (true) {
            $days <= 62 => self::day(),
            $days <= 400 => self::week(),
            default => self::month(),
        };
    }

    /** The bucket a date falls in. */
    public function keyFor(string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return match ($this->name) {
            'day' => $day->toDateString(),
            // Keyed by the Monday that opens the week, so the label and the
            // ordering are the same string and no separate sort is needed.
            'week' => $day->startOfWeek()->toDateString(),
            default => $day->format('Y-m'),
        };
    }

    /** What that bucket is called on an axis. */
    public function labelFor(string $key): string
    {
        return match ($this->name) {
            'day' => CarbonImmutable::parse($key)->format('j M'),
            'week' => CarbonImmutable::parse($key)->format('j M'),
            default => CarbonImmutable::parse($key.'-01')->format('M y'),
        };
    }

    /**
     * Every bucket in the window, in order, empty ones included.
     *
     * Bounded by {@see $maxBuckets}: an "all time" window on a workshop with one
     * stray opening entry dated 2019 would otherwise draw eighty months of
     * nothing to reach the four that matter. When the window overruns, the most
     * recent buckets are kept — a trend is read from the right-hand end.
     *
     * @param  array<int, string>  $observed  the raw dates the query returned
     * @return array<int, string>
     */
    public function keysIn(ReportPeriod $period, array $observed): array
    {
        [$from, $to] = self::span($period, $observed);

        if ($from === null || $to === null) {
            // Nothing to walk between — no window and no rows. Whatever came
            // back is the whole answer, folded to buckets and de-duplicated.
            $keys = array_values(array_unique(array_map(
                fn (string $date) => $this->keyFor($date),
                $observed,
            )));

            sort($keys);

            return $keys;
        }

        $keys = [];
        $cursor = CarbonImmutable::parse($this->keyFor($from->toDateString()).($this->name === 'month' ? '-01' : ''));
        $last = $this->keyFor($to->toDateString());

        // A hard ceiling on the loop as well as on the result. The window comes
        // from user input, and a report that recomputes per request must not be
        // able to spin on a date somebody typed wrong.
        for ($guard = 0; $guard < 2000; $guard++) {
            $key = $this->keyFor($cursor->toDateString());
            $keys[] = $key;

            if ($key === $last) {
                break;
            }

            $cursor = match ($this->name) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow(),
            };
        }

        return count($keys) > $this->maxBuckets
            ? array_slice($keys, -$this->maxBuckets)
            : $keys;
    }

    /**
     * The window to draw, which is the period where it has one and the observed
     * dates where it does not.
     *
     * @param  array<int, string>  $observed
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private static function span(ReportPeriod $period, array $observed): array
    {
        if ($period->from !== null && $period->to !== null) {
            return [CarbonImmutable::parse($period->from), CarbonImmutable::parse($period->to)];
        }

        $dates = array_values(array_filter(array_map(
            static fn ($value) => $value === null ? null : CarbonImmutable::parse((string) $value)->toDateString(),
            $observed,
        )));

        if ($dates === []) {
            return [null, null];
        }

        sort($dates);

        return [
            CarbonImmutable::parse($period->from ?? $dates[0]),
            CarbonImmutable::parse($period->to ?? $dates[count($dates) - 1]),
        ];
    }
}
