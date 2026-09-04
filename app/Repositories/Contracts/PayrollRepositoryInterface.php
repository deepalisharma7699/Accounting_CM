<?php

namespace App\Repositories\Contracts;

use App\Models\PayrollRun;
use App\Models\Transaction;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Payroll runs, their payslips, and the advances they recover — M22.
 *
 * ## Why the advance queries live here and not with employees
 *
 * Because they are one question with two halves. What is out with an employee is
 * the staff advances posted to them **less** what payroll has already recovered,
 * and those live in `transactions` and `payroll_lines` respectively. Splitting
 * the two across repositories would mean the subtraction happened somewhere that
 * could see only one of them, which is how a figure ends up computed twice and
 * differently. Both sides are read here; the arithmetic is
 * {@see \App\Services\Staff\PayrollService::advanceOutstandingFor()}'s.
 *
 * Every method is tenant-scoped by the global scope on the models involved.
 */
interface PayrollRepositoryInterface
{
    /* ---------------------------------------------------------------------
     | Runs
     |-------------------------------------------------------------------- */

    public function findRun(int $id): ?PayrollRun;

    /**
     * The run a given voucher posted, if any.
     *
     * For the retry path — M17. A second tap arriving after the first has
     * committed finds the transaction by its `client_ref`, and this is how it
     * gets from there to the run and its payslips. Exact rather than
     * {@see liveRunForMonth()}, which would answer with *a* run for the month and
     * would be the wrong one if the first attempt had since been reversed and
     * replaced.
     */
    public function runForTransaction(int $transactionId): ?PayrollRun;

    /**
     * The run that currently *is* this month's payroll, if there is one.
     *
     * Reversed runs are excluded, which is the whole reason this is not a unique
     * index: reversing a run is how a workshop frees the month to run it again.
     */
    public function liveRunForMonth(DateTimeInterface|string $month): ?PayrollRun;

    /**
     * @param  array{status?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, PayrollRun>
     */
    public function paginateRuns(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createRun(array $attributes): PayrollRun;

    /**
     * Write a run's payslips. One insert for the whole sheet.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function writeLines(PayrollRun $run, array $lines): void;

    /**
     * Every payslip for one employee, most recent month first — the drawer's
     * pay history.
     *
     * @return Collection<int, \App\Models\PayrollLine>
     */
    public function linesForEmployee(int $employeeId, int $limit = 12): Collection;

    /* ---------------------------------------------------------------------
     | Advances
     |-------------------------------------------------------------------- */

    /**
     * What has been **paid out** to each of these people, and what payroll has
     * **recovered** from them — in two queries for the whole set, not two per
     * person.
     *
     * Posted rows only, on both sides. A reversed advance is money that came
     * back and a reversed run recovered nothing, so counting either would leave
     * a workshop chasing a deduction that no longer exists.
     *
     * Every id is present in the result, zeroed where there is nothing.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array{paid: string, recovered: string}>
     */
    public function advanceTotalsFor(array $employeeIds): array;

    /**
     * The advance vouchers themselves, newest first — the Advances list.
     *
     * @param  array{employee_id?: int|null, status?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function paginateAdvances(array $filters, int $perPage): LengthAwarePaginator;
}
