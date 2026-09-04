<?php

namespace App\Services\Staff;

use App\Enums\SalaryBasis;
use App\Exceptions\ConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\Staff\EmployeeInUseException;
use App\Models\Employee;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\StaffDesignationRepositoryInterface;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Maintaining the list of who works for the workshop — M22.
 *
 * Nothing here posts anything, and nothing here holds a balance: what is out
 * with an employee is {@see PayrollService}'s answer, derived from the ledger
 * side on every read. This class is only concerned with the record — the name,
 * the trade, how they are paid, and whether they are still here.
 *
 * ## Leaving is a date, not a flag
 *
 * `left_on` and `is_active` are set together, and the date is the fact. Payroll
 * reads the dates because it has to keep answering correctly for a month being
 * run after somebody left — an employee archived in October was still owed for
 * September. `is_active` is only about *now*: whether they appear on today's day
 * sheet. Keeping the two in step is this service's job, so that nothing
 * downstream has to wonder which to trust.
 */
class EmployeeService
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $employees,
        private readonly StaffDesignationRepositoryInterface $designations,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return $this->employees->paginate($filters, $perPage);
    }

    /**
     * @return Collection<int, Employee>
     */
    public function all(bool $activeOnly = false): Collection
    {
        return $this->employees->all($activeOnly);
    }

    public function find(int $id): Employee
    {
        return $this->employees->findById($id)
            ?? throw new ResourceNotFoundException('Employee', $id);
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * @param  array{name: string, designation_id?: int|null, salary_basis?: string|null, pay_rate?: mixed, joined_on?: string|null, phone?: string|null, email?: string|null, address?: string|null, notes?: string|null}  $data
     */
    public function create(array $data): Employee
    {
        $name = $this->normaliseName($data['name']);

        $this->assertNameAvailable($name);

        $employee = $this->employees->create([
            'name' => $name,
            'designation_id' => $this->resolveDesignation($data['designation_id'] ?? null),
            'salary_basis' => $this->resolveBasis($data['salary_basis'] ?? null)->value,
            'pay_rate' => $this->resolveRate($data['pay_rate'] ?? null)->amount(),
            // Defaulted to today rather than refused: somebody adding the fitter
            // who started this morning should not have to think about it, and the
            // date is on the form for the one who started last year.
            'joined_on' => $this->resolveDate($data['joined_on'] ?? null) ?? CarbonImmutable::today(),
            'left_on' => null,
            'phone' => $this->trimmed($data['phone'] ?? null),
            'email' => $this->trimmed($data['email'] ?? null),
            'address' => $this->trimmed($data['address'] ?? null),
            'notes' => $this->trimmed($data['notes'] ?? null),
            'is_active' => true,
        ]);

        Log::info('staff.employee.created', [
            'employee_id' => $employee->id,
            'salary_basis' => $employee->salary_basis->value,
        ]);

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): Employee
    {
        $employee = $this->find($id);
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $name = $this->normaliseName((string) $data['name']);

            if ($name !== $employee->name) {
                $this->assertNameAvailable($name, $employee->id);
                $attributes['name'] = $name;
            }
        }

        if (array_key_exists('designation_id', $data)) {
            $attributes['designation_id'] = $this->resolveDesignation($data['designation_id']);
        }

        if (array_key_exists('salary_basis', $data)) {
            $attributes['salary_basis'] = $this->resolveBasis($data['salary_basis'])->value;
        }

        if (array_key_exists('pay_rate', $data)) {
            $attributes['pay_rate'] = $this->resolveRate($data['pay_rate'])->amount();
        }

        if (array_key_exists('joined_on', $data)) {
            $joined = $this->resolveDate($data['joined_on']);

            if ($joined !== null) {
                $attributes['joined_on'] = $joined;
            }
        }

        /*
        | Leaving, and coming back.
        |
        | The two flags move together here so nothing downstream has to reconcile
        | them: setting a leaving date archives the record, and clearing one puts
        | somebody back on the day sheet. A caller can still override `is_active`
        | explicitly below — a workshop suspending somebody without a leaving
        | date is a real case — which is why this is not the last word.
        */
        if (array_key_exists('left_on', $data)) {
            $left = $this->resolveDate($data['left_on']);

            $attributes['left_on'] = $left;
            $attributes['is_active'] = $left === null;
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        foreach (['phone', 'email', 'address', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $this->trimmed($data[$field]);
            }
        }

        if ($attributes === []) {
            return $employee;
        }

        $employee = $this->employees->update($employee, $attributes);

        Log::info('staff.employee.updated', [
            'employee_id' => $employee->id,
            'fields' => array_keys($attributes),
        ]);

        return $employee;
    }

    /**
     * Remove somebody nothing points at — a typo, or a duplicate caught the same
     * afternoon.
     *
     * Anything with attendance, a payslip or an advance behind it is refused.
     * The foreign keys would refuse it too; this exists so the answer names what
     * is in the way and offers the thing that was actually meant, which is
     * almost always "mark them as having left".
     */
    public function delete(int $id): void
    {
        $employee = $this->find($id);
        $counts = $this->employees->referenceCounts($employee->id);

        if (array_sum($counts) > 0) {
            throw EmployeeInUseException::hasRecords($employee->id, $employee->name, $counts);
        }

        $this->employees->delete($employee);

        Log::info('staff.employee.deleted', ['employee_id' => $employee->id]);
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    private function normaliseName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * A designation this workshop actually has, or none.
     *
     * Resolved through the tenant-scoped repository rather than trusted, so an
     * id belonging to another workshop reads as "not found" and becomes null
     * rather than reaching the column. That is the one place a cross-tenant id
     * could otherwise slip in, because the foreign key alone would accept it.
     */
    private function resolveDesignation(mixed $id): ?int
    {
        if ($id === null || $id === '' || (int) $id === 0) {
            return null;
        }

        return $this->designations->findById((int) $id)?->id;
    }

    private function resolveBasis(mixed $basis): SalaryBasis
    {
        return SalaryBasis::tryFrom((string) $basis) ?? SalaryBasis::Monthly;
    }

    /**
     * A rate is never negative. Zero is allowed, and means it — somebody on the
     * books whose pay has not been agreed yet, or an owner who takes drawings
     * rather than a salary. They appear on the attendance sheet and compute to
     * nothing on payroll, which is the correct treatment of both.
     */
    private function resolveRate(mixed $rate): Money
    {
        $money = Money::ofNullable(is_string($rate) || is_int($rate) || is_float($rate) ? $rate : null)
            ?? Money::zero();

        if ($money->isNegative()) {
            throw new ConflictException(
                'A pay rate cannot be negative.',
                'EMPLOYEE_RATE_INVALID',
                ['field' => 'pay_rate'],
            );
        }

        return $money;
    }

    private function resolveDate(mixed $date): ?CarbonImmutable
    {
        $date = trim((string) ($date ?? ''));

        return $date === '' ? null : CarbonImmutable::parse($date)->startOfDay();
    }

    private function trimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function assertNameAvailable(string $name, ?int $exceptId = null): void
    {
        if (! $this->employees->nameExists($name, $exceptId)) {
            return;
        }

        throw new ConflictException(
            "Somebody called \"{$name}\" is already on the staff list. Two rows with one name means one of ".
            'them gets marked present every day and the other is paid nothing, and both look right on their '.
            'own screen — so give this one something that tells them apart, like the trade or the village.',
            'EMPLOYEE_NAME_TAKEN',
            ['field' => 'name'],
        );
    }
}
