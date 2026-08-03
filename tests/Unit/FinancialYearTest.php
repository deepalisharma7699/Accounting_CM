<?php

namespace Tests\Unit;

use App\Models\Tenant;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Financial-year and go-live date arithmetic.
 *
 * An April financial year creates an off-by-one that every hand-rolled report
 * gets wrong — 10 February 2026 belongs to the year that *opened* on 1 April
 * 2025 — so it is computed in one place and pinned down here before any report
 * depends on it.
 *
 * Extends the application TestCase rather than PHPUnit's: Eloquent's `date`
 * cast resolves the container. No database is touched and no RefreshDatabase
 * is used, so this still runs in milliseconds.
 */
class FinancialYearTest extends TestCase
{
    private function tenant(int $startMonth = 4, ?string $booksStart = null): Tenant
    {
        $tenant = new Tenant([
            'financial_year_start_month' => $startMonth,
            'timezone' => 'Asia/Kolkata',
        ]);

        if ($booksStart !== null) {
            $tenant->books_start_date = $booksStart;
        }

        return $tenant;
    }

    #[Test]
    #[DataProvider('aprilYears')]
    public function it_places_a_date_in_the_right_april_financial_year(
        string $date,
        string $expectedStart,
        string $expectedEnd,
    ): void {
        [$start, $end] = $this->tenant()->financialYearFor(new DateTimeImmutable($date));

        $this->assertSame($expectedStart, $start->toDateString());
        $this->assertSame($expectedEnd, $end->toDateString());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function aprilYears(): array
    {
        return [
            'the day the year opens' => ['2026-04-01', '2026-04-01', '2027-03-31'],
            'mid-year' => ['2026-09-15', '2026-04-01', '2027-03-31'],
            'the day before it opens' => ['2026-03-31', '2025-04-01', '2026-03-31'],
            'February belongs to the previous year' => ['2026-02-10', '2025-04-01', '2026-03-31'],
            'the first day of the calendar year' => ['2026-01-01', '2025-04-01', '2026-03-31'],
            'a leap year end' => ['2024-02-29', '2023-04-01', '2024-03-31'],
        ];
    }

    #[Test]
    public function a_january_start_makes_the_financial_year_the_calendar_year(): void
    {
        [$start, $end] = $this->tenant(1)->financialYearFor(new DateTimeImmutable('2026-07-15'));

        $this->assertSame('2026-01-01', $start->toDateString());
        $this->assertSame('2026-12-31', $end->toDateString());
    }

    #[Test]
    public function a_december_start_spans_the_new_year(): void
    {
        [$start, $end] = $this->tenant(12)->financialYearFor(new DateTimeImmutable('2026-01-15'));

        $this->assertSame('2025-12-01', $start->toDateString());
        $this->assertSame('2026-11-30', $end->toDateString());
    }

    #[Test]
    public function consecutive_financial_years_do_not_overlap_or_leave_a_gap(): void
    {
        [, $end] = $this->tenant()->financialYearFor(new DateTimeImmutable('2026-06-01'));
        [$nextStart] = $this->tenant()->financialYearFor(new DateTimeImmutable('2027-06-01'));

        // The next year opens the day after this one closes. A gap would hide
        // transactions from every report; an overlap would double-count them.
        $this->assertSame($nextStart->toDateString(), $end->addDay()->toDateString());
    }

    /* ---------------------------------------------------------------------
     | Go-live date
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_workshop_with_no_go_live_date_accepts_any_date(): void
    {
        $tenant = $this->tenant();

        $this->assertTrue($tenant->acceptsPostingOn(new DateTimeImmutable('1999-01-01')));
        $this->assertTrue($tenant->acceptsPostingOn(new DateTimeImmutable('2030-01-01')));
    }

    #[Test]
    public function nothing_may_be_posted_before_the_go_live_date(): void
    {
        $tenant = $this->tenant(booksStart: '2026-04-01');

        // That period belongs to whatever the workshop used previously; its
        // closing position arrives as opening balances instead.
        $this->assertFalse($tenant->acceptsPostingOn(new DateTimeImmutable('2026-03-31')));
        $this->assertTrue($tenant->acceptsPostingOn(new DateTimeImmutable('2026-04-01')));
        $this->assertTrue($tenant->acceptsPostingOn(new DateTimeImmutable('2026-04-02')));
    }

    #[Test]
    public function the_go_live_date_comparison_ignores_the_time_of_day(): void
    {
        $tenant = $this->tenant(booksStart: '2026-04-01');

        // A transaction at 00:30 on go-live day is on go-live day.
        $this->assertTrue($tenant->acceptsPostingOn(new DateTimeImmutable('2026-04-01 00:30:00')));
        $this->assertFalse($tenant->acceptsPostingOn(new DateTimeImmutable('2026-03-31 23:59:59')));
    }
}
