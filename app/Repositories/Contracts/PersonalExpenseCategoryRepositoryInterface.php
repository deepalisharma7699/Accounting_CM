<?php

namespace App\Repositories\Contracts;

use App\Models\PersonalExpenseCategory;
use Illuminate\Support\Collection;

/**
 * The owner's personal categories. Tenant-scoped by the global scope on the
 * model, and never paginated: a family has a dozen of these and the form needs
 * every one.
 */
interface PersonalExpenseCategoryRepositoryInterface
{
    public function findById(int $id): ?PersonalExpenseCategory;

    public function nameExists(string $name, ?int $exceptId = null): bool;

    /**
     * @return Collection<int, PersonalExpenseCategory>
     */
    public function all(bool $activeOnly = false): Collection;

    public function nextDisplayOrder(): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PersonalExpenseCategory;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(PersonalExpenseCategory $category, array $attributes): PersonalExpenseCategory;

    public function delete(PersonalExpenseCategory $category): bool;

    /**
     * How many entries each category holds, in one query, zero where none.
     *
     * @param  array<int, int>  $categoryIds
     * @return array<int, int>
     */
    public function expenseCounts(array $categoryIds): array;
}
