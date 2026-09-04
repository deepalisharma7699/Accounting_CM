<?php

namespace App\Services\Staff;

use App\Enums\AttendanceStatus;
use App\Models\Employee;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\StaffAttendanceRepositoryInterface;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The attendance sheet — M22.
 *
 * Two reads and one write, and the shape of each is decided by how a workshop
 * actually keeps this record:
 *
 *   the day sheet — everybody on the payroll today, with whatever mark they
 *                   already have. Opened, filled in and saved in one go, several
 *                   times a day as people arrive and leave.
 *   the register  — one row per person, one column per day, for a month. The
 *                   thing that gets printed and stuck on a wall, and the thing
 *                   somebody scans when they suspect a payslip is wrong.
 *   marking       — an upsert of the whole sheet, because a correction is the
 *                   normal case rather than the exception.
 *
 * ## Nothing here computes money
 *
 * That is {@see PayrollCalculator}, deliberately and completely. This service
 * decides what was recorded; the calculator decides what it is worth. Putting a
 * day rate anywhere in this file would be the second implementation CLAUDE.md
 * §4.4 exists to prevent, and it would be the copy that stayed wrong longest,
 * because attendance screens are looked at daily and payroll monthly.
 */
class AttendanceService
{
    public function __construct(
        private readonly StaffAttendanceRepositoryInterface $attendance,
        private readonly EmployeeRepositoryInterface $employees,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * One day: everybody who was on the payroll that day, and their mark.
     *
     * **In service on the day, not active today**, and the difference matters
     * even for a sheet about the present: opening yesterday's sheet after
     * somebody left this morning must still show them, or the day they worked
     * cannot be corrected.
     *
     * A row with a null `status` is somebody nobody has marked. That is a real
     * state rather than missing data — see
     * {@see \App\Enums\SalaryBasis::unmarkedDayIsPaid()} — so it is returned as
     * one instead of being defaulted to present here.
     *
     * @return array{
     *     date: string,
     *     rows: array<int, array{employee: Employee, status: string|null, notes: string|null}>,
     *     counts: array<string, int>
     * }
     */
    public function daySheet(DateTimeInterface|string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        $employees = $this->employees->inServiceBetween($day, $day);
        $marks = $this->attendance->forDate($day);

        $rows = [];
        $counts = ['unmarked' => 0];

        foreach ($employees as $employee) {
            $mark = $marks->get((int) $employee->id);
            $status = $mark?->status;

            $rows[] = [
                'employee' => $employee,
                'status' => $status?->value,
                'notes' => $mark?->notes,
            ];

            $key = $status?->value ?? 'unmarked';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return [
            'date' => $day->toDateString(),
            'rows' => $rows,
            'counts' => $counts,
        ];
    }

    /**
     * One month: a row per person, a column per day.
     *
     * The grid is returned sparse — a date only appears against somebody where
     * there is a mark — because that is what the table holds, and filling it in
     * here would mean deciding what an unmarked day means in a second place.
     * The client paints the gaps; the calculator prices them.
     *
     * @return array{
     *     from: string,
     *     to: string,
     *     days: int,
     *     rows: array<int, array{employee: Employee, marks: array<string, array{status: string, notes: string|null}>, counts: array<string, int>}>
     * }
     */
    public function register(DateTimeInterface|string $month): array
    {
        $start = PayrollCalculator::monthStart($month);
        $end = $start->endOfMonth()->startOfDay();

        $employees = $this->employees->inServiceBetween($start, $end);
        $marks = $this->attendance->forPeriod($start, $end, $employees->pluck('id')->all());

        /** @var array<int, array<string, array{status: string, notes: string|null}>> $byEmployee */
        $byEmployee = [];
        /** @var array<int, array<string, int>> $counts */
        $counts = [];

        foreach ($marks as $mark) {
            $id = (int) $mark->employee_id;
            $status = $mark->status->value;

            $byEmployee[$id][$mark->date->toDateString()] = [
                'status' => $status,
                'notes' => $mark->notes,
            ];

            $counts[$id][$status] = ($counts[$id][$status] ?? 0) + 1;
        }

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'days' => $start->daysInMonth,
            'rows' => $employees->map(fn (Employee $employee) => [
                'employee' => $employee,
                'marks' => $byEmployee[(int) $employee->id] ?? [],
                'counts' => $counts[(int) $employee->id] ?? [],
            ])->all(),
        ];
    }

    /**
     * How many of each status each of these people has in a period — the "this
     * month" column on the staff list, in one query rather than one per row.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<string, int>>
     */
    public function summariesFor(array $employeeIds, DateTimeInterface|string $from, DateTimeInterface|string $to): array
    {
        return $this->attendance->statusCountsFor($employeeIds, $from, $to);
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * Save a day's marks.
     *
     * The whole sheet arrives at once, and a row whose status is null **clears**
     * the mark rather than being ignored — going back to unmarked is a
     * correction somebody genuinely makes, and without it a mis-tap could only
     * be replaced, never undone.
     *
     * Every employee id is resolved through the tenant-scoped repository before
     * anything is written. That is load-bearing: the upsert below goes round the
     * model and therefore round the tenant scope, so this is the only thing
     * standing between an id from another workshop and a row in these books.
     *
     * @param  array<int, array{employee_id: int|string, status?: string|null, notes?: string|null}>  $rows
     * @return array{marked: int, cleared: int, date: string}
     */
    public function mark(DateTimeInterface|string $date, array $rows): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        // Resolved once, as a set, so a sheet of nine people is one query rather
        // than nine — and so an id this workshop does not own is simply not in
        // the set and cannot be written.
        $known = $this->employees->all()->keyBy(fn (Employee $employee) => (int) $employee->id);

        $upserts = [];
        $clears = [];

        foreach ($rows as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);

            if (! $known->has($employeeId)) {
                continue;
            }

            $status = AttendanceStatus::tryFrom((string) ($row['status'] ?? ''));

            if ($status === null) {
                $clears[] = $employeeId;

                continue;
            }

            $notes = trim((string) ($row['notes'] ?? ''));

            $upserts[] = [
                'employee_id' => $employeeId,
                'date' => $day->toDateString(),
                'status' => $status->value,
                'notes' => $notes === '' ? null : $notes,
            ];
        }

        // One wrapper: a sheet that half saved would leave a day marked for the
        // first four people and cleared for the rest, with nothing to say so.
        [$marked, $cleared] = DB::transaction(fn () => [
            $this->attendance->upsertMany($upserts),
            $this->attendance->clear($clears, $day),
        ]);

        Log::info('staff.attendance.marked', [
            'date' => $day->toDateString(),
            'marked' => $marked,
            'cleared' => $cleared,
        ]);

        return [
            'marked' => $marked,
            'cleared' => $cleared,
            'date' => $day->toDateString(),
        ];
    }

    /**
     * Every mark in a period, for the payroll calculator.
     *
     * @param  array<int, int>|null  $employeeIds
     * @return Collection<int, \App\Models\StaffAttendance>
     */
    public function marksBetween(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        ?array $employeeIds = null,
    ): Collection {
        return $this->attendance->forPeriod($from, $to, $employeeIds);
    }
}
