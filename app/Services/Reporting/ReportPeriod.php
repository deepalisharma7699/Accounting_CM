<?php

namespace App\Services\Reporting;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * The window a report covers.
 *
 * The roadmap asks that reports "respect the financial year from M2.2", and this
 * is where that happens — once, rather than in each report. A workshop whose
 * year opens in April has a "this year" that runs 1 April to 31 March, and every
 * hand-rolled version of that arithmetic gets the off-by-one wrong in February.
 * {@see Tenant::financialYearFor()} already computes it; this turns the answer
 * into something a report can be handed.
 *
 * ## Why a preset and not two dates
 *
 * Because "this financial year" is the question people actually ask, and turning
 * it into a pair of dates in the client means the client owns the workshop's
 * year-start setting. It would then be right until somebody changed the setting
 * on the workshop screen, at which point every saved bookmark would quietly
 * report the wrong twelve months.
 *
 * Custom dates are still accepted, because an accountant closing a month wants
 * exactly the month.
 *
 * ## Timezone
 *
 * Resolved in the *workshop's* timezone, not the server's. "Today" for a
 * workshop in Asia/Kolkata is a different day from "today" on a UTC server for
 * five and a half hours of every day, and a day book that dropped the evening's
 * takings would be reported as data loss.
 */
final class ReportPeriod
{
    private function __construct(
        public readonly string $preset,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $label,
    ) {}

    /**
     * The presets a client may ask for, with what each one means.
     *
     * `all` is deliberately present and deliberately first in nobody's mind: a
     * trial balance over all time is the one that has to reconcile, and a report
     * that could not be run without picking a period would make the invariant
     * harder to check than it is to hold.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function presets(): array
    {
        return [
            ['value' => 'this_financial_year', 'label' => 'This financial year'],
            ['value' => 'last_financial_year', 'label' => 'Last financial year'],
            ['value' => 'this_month', 'label' => 'This month'],
            ['value' => 'last_month', 'label' => 'Last month'],
            ['value' => 'this_quarter', 'label' => 'This quarter'],
            ['value' => 'today', 'label' => 'Today'],
            ['value' => 'all', 'label' => 'Everything so far'],
            ['value' => 'custom', 'label' => 'Chosen dates'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function presetValues(): array
    {
        return array_column(self::presets(), 'value');
    }

    /**
     * Resolve a request into a window.
     *
     * Two explicit dates win over a preset, because somebody who typed them
     * meant them. A preset with no workshop behind it — a platform admin, or a
     * test with no tenant — falls back to all time rather than to the server's
     * idea of April.
     */
    public static function resolve(?string $preset, ?string $from = null, ?string $to = null, ?Tenant $tenant = null): self
    {
        $preset = trim((string) $preset);

        if ($preset === '' || $preset === 'custom') {
            return self::custom($from, $to);
        }

        if (! in_array($preset, self::presetValues(), true)) {
            // An unknown preset is not worth an error. The honest reading of
            // "show me the widgets" is everything, and a report that refused to
            // draw because a query string was stale would be worse than one that
            // drew a window the caller can see stated on it.
            return self::everything();
        }

        $timezone = $tenant?->timezone ?? config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return match ($preset) {
            'this_financial_year' => self::financialYear($tenant, $today, 0),
            'last_financial_year' => self::financialYear($tenant, $today, -1),
            'this_month' => self::between($today->startOfMonth(), $today->endOfMonth(), $today->format('F Y'), 'this_month'),
            'last_month' => self::month($today->subMonthNoOverflow()),
            'this_quarter' => self::between(
                $today->startOfQuarter(),
                $today->endOfQuarter(),
                sprintf('Quarter to %s', $today->endOfQuarter()->format('j M Y')),
                'this_quarter',
            ),
            'today' => self::between($today, $today, $today->format('j M Y'), 'today'),
            default => self::everything(),
        };
    }

    /**
     * The workshop's own financial year, offset by whole years.
     *
     * Delegated to the model rather than recomputed, because the April
     * off-by-one is exactly the thing M2.2 put in one place — and a second
     * implementation here would be a second thing to get wrong.
     */
    private static function financialYear(?Tenant $tenant, CarbonImmutable $today, int $offset): self
    {
        if ($tenant === null) {
            return self::everything();
        }

        [$start, $end] = $tenant->financialYearFor($today->addYears($offset));

        return self::between(
            $start,
            $end,
            sprintf('FY %s–%s', $start->format('Y'), $end->format('y')),
            $offset === 0 ? 'this_financial_year' : 'last_financial_year',
        );
    }

    private static function month(CarbonImmutable $day): self
    {
        return self::between(
            $day->startOfMonth(),
            $day->endOfMonth(),
            $day->format('F Y'),
            'last_month',
        );
    }

    private static function between(CarbonImmutable $from, CarbonImmutable $to, string $label, string $preset): self
    {
        return new self($preset, $from->toDateString(), $to->toDateString(), $label);
    }

    public static function everything(): self
    {
        return new self('all', null, null, 'Everything so far');
    }

    public static function custom(?string $from, ?string $to): self
    {
        $from = filled($from) ? CarbonImmutable::parse($from)->toDateString() : null;
        $to = filled($to) ? CarbonImmutable::parse($to)->toDateString() : null;

        if ($from === null && $to === null) {
            return self::everything();
        }

        // Swapped rather than refused. Somebody who filled the two date boxes in
        // the wrong order wants the range between them, and a report that
        // answered "no entries" would look like a workshop with no trade.
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self('custom', $from, $to, self::describe($from, $to));
    }

    private static function describe(?string $from, ?string $to): string
    {
        $format = static fn (string $date) => CarbonImmutable::parse($date)->format('j M Y');

        return match (true) {
            $from !== null && $to !== null => sprintf('%s to %s', $format($from), $format($to)),
            $from !== null => sprintf('From %s', $format($from)),
            $to !== null => sprintf('Up to %s', $format($to)),
            default => 'Everything so far',
        };
    }

    public function coversEverything(): bool
    {
        return $this->from === null && $this->to === null;
    }

    /**
     * The window immediately before this one, the same length — what every
     * headline figure is compared against.
     *
     * A number on its own is not an insight. "₹4,20,000" says nothing a
     * workshop can act on; "₹4,20,000, up 18% on last month" says whether the
     * month went well. That comparison is the difference between this module and
     * the statements it sits beside, so it is computed once here rather than in
     * each panel.
     *
     * **The length is what is preserved, not the calendar name.** The window
     * before 1–31 March is 29 January to 28 February, not "February" — comparing
     * a 31-day month against a 28-day one would report a 10% fall in a shop that
     * traded identically every day. February is what the picker is for.
     *
     * Null where there is nothing to compare against:
     *
     *   * **all time** has no before;
     *   * an **open-ended** custom range — "from 1 April", "up to today" — has
     *     no length to step back by.
     *
     * A caller that gets null reports the figure with no delta beside it, which
     * is the honest outcome. Inventing a baseline would put a percentage on the
     * screen that means nothing.
     */
    public function previous(): ?self
    {
        if ($this->from === null || $this->to === null) {
            return null;
        }

        $from = CarbonImmutable::parse($this->from);
        $to = CarbonImmutable::parse($this->to);

        // Inclusive of both ends: 1–31 March is 31 days, not 30. The window
        // before it therefore ends on 28 February and starts 31 days earlier.
        $days = $from->diffInDays($to) + 1;

        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        return new self(
            'previous',
            $previousFrom->toDateString(),
            $previousTo->toDateString(),
            self::describe($previousFrom->toDateString(), $previousTo->toDateString()),
        );
    }

    /**
     * @return array{preset: string, from: string|null, to: string|null, label: string}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->from,
            'to' => $this->to,
            'label' => $this->label,
        ];
    }
}
