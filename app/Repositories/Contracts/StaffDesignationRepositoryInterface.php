<?php

namespace App\Repositories\Contracts;

use App\Models\StaffDesignation;
use Illuminate\Support\Collection;

/**
 * The Designation Master — M22. Tenant-scoped by the global scope on the model.
 *
 * No pagination anywhere here, deliberately: a workshop has a dozen designations
 * and the form needs all of them at once. A picker fed a page is a picker that
 * silently cannot offer the thirteenth.
 */
interface StaffDesignationRepositoryInterface
{
    public function findById(int $id): ?StaffDesignation;

    public function nameExists(string $name, ?int $exceptId = null): bool;

    /**
     * @return Collection<int, StaffDesignation>
     */
    public function all(bool $activeOnly = false): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): StaffDesignation;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StaffDesignation $designation, array $attributes): StaffDesignation;

    /**
     * The trades a sale asks about by name, active ones only — M22.
     *
     * Its own method rather than a filter argument on {@see all()}, because the
     * sale form's question is not the master screen's: the screen wants every
     * row including the archived ones, and the form wants exactly the ones it
     * should paint a picker for.
     *
     * @return Collection<int, StaffDesignation>
     */
    public function trackedOnSales(): Collection;

    /**
     * Only ever a designation nothing points at — {@see employeeCount()} is
     * checked first. One that is in use is archived instead, so the employees
     * filed under it keep the word that explains their pay grade.
     */
    public function delete(StaffDesignation $designation): bool;

    public function employeeCount(int $designationId): int;

    /**
     * How many invoices have been attributed to this trade — M22.
     *
     * Counted alongside {@see employeeCount()} before a designation may be
     * deleted. `transaction_staff.designation_id` is `restrictOnDelete`, so
     * without this the database would refuse the delete with an integrity error
     * — a 500 where the caller needs a sentence explaining that the trade is on
     * eleven invoices and should be archived instead.
     */
    public function attributionCount(int $designationId): int;

    /**
     * How many employees each designation holds, in one query for the whole
     * list — so the master screen can say "Helper · 4" without one query per row.
     *
     * Every designation is present in the result, zero where nobody holds it:
     * "used by nobody" is an answer, and the screen should not have to tell it
     * apart from "not counted".
     *
     * @param  array<int, int>  $designationIds
     * @return array<int, int>
     */
    public function employeeCounts(array $designationIds): array;
}
