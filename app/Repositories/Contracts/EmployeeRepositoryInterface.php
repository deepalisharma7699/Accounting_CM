<?php

namespace App\Repositories\Contracts;

use App\Models\Employee;
use App\Services\Staff\PayrollService;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The people who work for the workshop — M22. Every method is tenant-scoped by
 * the global scope on {@see Employee}.
 *
 * Note what is absent: nothing here reads or writes an advance balance, because
 * an employee has none. What is out with somebody is derived from the advances
 * posted against them less what payroll recovered — see
 * {@see PayrollService::advanceOutstandingFor()}.
 */
interface EmployeeRepositoryInterface
{
    public function findById(int $id): ?Employee;

    public function nameExists(string $name, ?int $exceptId = null): bool;

    /**
     * Everybody, for a picker or a day sheet. Small by nature — a workshop has
     * staff in the tens — so it is fetched whole rather than paginated.
     *
     * @return Collection<int, Employee>
     */
    public function all(bool $activeOnly = false): Collection;

    /**
     * Everybody who was on the payroll at any point in a period.
     *
     * **Not the same set as `all(activeOnly: true)`**, and payroll needs this
     * one: somebody who left on the 12th is owed for eleven days and appears on
     * no active list by the time the month is run, while somebody joining next
     * month is active today and is owed nothing for this one.
     *
     * @return Collection<int, Employee>
     */
    public function inServiceBetween(DateTimeInterface|string $from, DateTimeInterface|string $to): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Employee;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, array $attributes): Employee;

    /**
     * Only ever an employee nothing points at. Anything with attendance, a
     * payslip or an advance behind it is archived instead — the foreign keys
     * refuse it regardless, and this exists so the answer is an explanation
     * rather than a constraint violation.
     */
    public function delete(Employee $employee): bool;

    /**
     * How many rows point at this employee, across every table that does.
     *
     * One figure rather than three, because the caller's question is "may this
     * be deleted" and the answer is the same whichever table said no. The
     * message that goes with the refusal names them separately.
     *
     * @return array{attendance: int, payslips: int, advances: int, attributions: int}
     */
    public function referenceCounts(int $employeeId): array;

    /**
     * @param  array{search?: string|null, designation_id?: int|null, salary_basis?: string|null, is_active?: bool|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
