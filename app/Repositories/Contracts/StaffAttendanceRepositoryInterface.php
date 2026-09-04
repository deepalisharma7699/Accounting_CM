<?php

namespace App\Repositories\Contracts;

use App\Models\StaffAttendance;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The attendance sheet — M22. Tenant-scoped by the global scope on
 * {@see StaffAttendance}.
 *
 * The one thing to keep in mind reading this: **there is no row for an ordinary
 * day.** A month of nine people is 270 rows if every day is written and perhaps
 * twenty if only the departures from normal are, and the sheet is filled in by
 * somebody at a bench rather than by a payroll clerk. So every read here returns
 * a sparse set, and the absence of a mark is meaningful rather than missing data
 * — see {@see \App\Enums\SalaryBasis::unmarkedDayIsPaid()}.
 */
interface StaffAttendanceRepositoryInterface
{
    /**
     * Every mark for one day, keyed by employee id.
     *
     * @return Collection<int, StaffAttendance>
     */
    public function forDate(DateTimeInterface|string $date): Collection;

    /**
     * Every mark in a period, optionally for a named set of people.
     *
     * Ordered by employee and then by date, which is the order both readers want
     * — the month register lays out one row per person, and payroll walks one
     * person's days at a time.
     *
     * @param  array<int, int>|null  $employeeIds  null for everybody.
     * @return Collection<int, StaffAttendance>
     */
    public function forPeriod(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?array $employeeIds = null,
    ): Collection;

    /**
     * How many of each status each person has in a period, in one query.
     *
     * `[employeeId => ['present' => 22, 'absent' => 1, ...]]`, holding only the
     * statuses that actually occur. For the staff list's "this month" column,
     * which would otherwise be one query per row.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<string, int>>
     */
    public function statusCountsFor(
        array $employeeIds,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
    ): array;

    /**
     * Write a day's marks, replacing whatever was there.
     *
     * An upsert rather than an insert, because the day sheet is opened,
     * corrected and saved again as often as somebody remembers something — and
     * each save has to leave one row per person rather than another one. The
     * unique index would refuse the second anyway; this is what makes the
     * correction work instead of failing.
     *
     * @param  array<int, array{employee_id: int, date: string, status: string, notes: string|null}>  $rows
     * @return int  How many rows were written.
     */
    public function upsertMany(array $rows): int;

    /**
     * Remove the marks for a set of people on one day.
     *
     * Deleting rather than storing an "unmarked" status, because unmarked is the
     * absence of a row and always has been — a status meaning "no status" would
     * be a second way to say the same thing, and the two would eventually
     * disagree.
     *
     * @param  array<int, int>  $employeeIds
     */
    public function clear(array $employeeIds, DateTimeInterface|string $date): int;
}
