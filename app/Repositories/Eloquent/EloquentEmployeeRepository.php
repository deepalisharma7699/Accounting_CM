<?php

namespace App\Repositories\Eloquent;

use App\Enums\SalaryBasis;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\StaffAttendance;
use App\Models\Transaction;
use App\Models\TransactionStaff;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentEmployeeRepository implements EmployeeRepositoryInterface
{
    /**
     * Columns a client may sort by, so nothing user-supplied reaches ORDER BY.
     *
     * `pay_rate` is on the list and is the one worth a note: it sorts a monthly
     * salary against a daily wage, which compares two different things. The
     * screen groups by basis before it sorts, so the comparison never happens in
     * front of anybody — and refusing the sort outright would take away the one
     * question the column exists to answer.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['name', 'joined_on', 'pay_rate', 'created_at'];

    public function findById(int $id): ?Employee
    {
        return Employee::with('designation')->find($id);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return Employee::where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function all(bool $activeOnly = false): Collection
    {
        return Employee::query()
            ->with('designation')
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('name')
            ->get();
    }

    public function inServiceBetween(DateTimeInterface|string $from, DateTimeInterface|string $to): Collection
    {
        return Employee::query()
            ->with('designation')
            ->inServiceBetween($from, $to)
            ->orderBy('name')
            ->get();
    }

    public function create(array $attributes): Employee
    {
        return Employee::create($attributes)->load('designation');
    }

    public function update(Employee $employee, array $attributes): Employee
    {
        $employee->fill($attributes)->save();

        return $employee->refresh()->load('designation');
    }

    public function delete(Employee $employee): bool
    {
        return (bool) $employee->delete();
    }

    public function referenceCounts(int $employeeId): array
    {
        return [
            'attendance' => StaffAttendance::where('employee_id', $employeeId)->count(),
            'payslips' => PayrollLine::where('employee_id', $employeeId)->count(),
            // Every transaction stamped with them, drafts included — a draft
            // advance has not reached the ledger, but deleting the person out
            // from under it would leave a voucher that can never be posted.
            'advances' => Transaction::where('employee_id', $employeeId)->count(),
            // Sales credited to them — M22. `transaction_staff.employee_id` is
            // restrictOnDelete, so without this the database refuses the delete
            // with an integrity error where the caller needs a sentence. The
            // record matters for the same reason a payslip's does: an invoice
            // that has lost the name of who did the work cannot get it back.
            'attributions' => TransactionStaff::where('employee_id', $employeeId)->count(),
        ];
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'name';

        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        return Employee::query()
            ->with('designation')
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where(function ($query) use ($filters) {
                    $term = '%'.$filters['search'].'%';

                    $query->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('email', 'like', $term);
                })
            )
            ->when(
                ($filters['designation_id'] ?? null) !== null,
                fn ($query) => $query->where('designation_id', $filters['designation_id'])
            )
            ->when(
                filled($filters['salary_basis'] ?? null)
                    && SalaryBasis::tryFrom((string) $filters['salary_basis']) !== null,
                fn ($query) => $query->where('salary_basis', $filters['salary_basis'])
            )
            ->when(
                ($filters['is_active'] ?? null) !== null,
                fn ($query) => $query->where('is_active', $filters['is_active'])
            )
            ->orderBy($sort, $direction)
            // A stable tiebreaker: names are unique per workshop, but a joining
            // date is not, and without this a page boundary can repeat or skip.
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
