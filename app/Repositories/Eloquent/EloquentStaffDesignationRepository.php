<?php

namespace App\Repositories\Eloquent;

use App\Models\Employee;
use App\Models\StaffDesignation;
use App\Models\TransactionStaff;
use App\Repositories\Contracts\StaffDesignationRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentStaffDesignationRepository implements StaffDesignationRepositoryInterface
{
    public function findById(int $id): ?StaffDesignation
    {
        return StaffDesignation::find($id);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return StaffDesignation::where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function all(bool $activeOnly = false): Collection
    {
        return StaffDesignation::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('name')
            ->get();
    }

    public function trackedOnSales(): Collection
    {
        return StaffDesignation::query()
            ->active()
            ->trackedOnSales()
            // Alphabetical, so the boxes on the sale form keep the same order
            // from one invoice to the next. A clerk fills these in by position
            // after the first week.
            ->orderBy('name')
            ->get();
    }

    public function create(array $attributes): StaffDesignation
    {
        return StaffDesignation::create($attributes);
    }

    public function update(StaffDesignation $designation, array $attributes): StaffDesignation
    {
        $designation->fill($attributes)->save();

        return $designation->refresh();
    }

    public function delete(StaffDesignation $designation): bool
    {
        return (bool) $designation->delete();
    }

    public function employeeCount(int $designationId): int
    {
        return Employee::where('designation_id', $designationId)->count();
    }

    public function attributionCount(int $designationId): int
    {
        return TransactionStaff::where('designation_id', $designationId)->count();
    }

    public function employeeCounts(array $designationIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $designationIds)));

        $counts = array_fill_keys($ids, 0);

        if ($ids === []) {
            return $counts;
        }

        $rows = Employee::query()
            ->whereIn('designation_id', $ids)
            ->groupBy('designation_id')
            ->selectRaw('designation_id, COUNT(*) as employees')
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->designation_id] = (int) $row->employees;
        }

        return $counts;
    }
}
