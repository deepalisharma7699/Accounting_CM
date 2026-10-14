<?php

namespace App\Repositories\Contracts;

use App\Models\PersonalExpense;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The owner's personal diary. Tenant-scoped by the global scope on the model.
 */
interface PersonalExpenseRepositoryInterface
{
    public function findById(int $id): ?PersonalExpense;

    /**
     * @param  array{search?: string|null, category_id?: int|null, paid_by?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, PersonalExpense>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * What the filtered entries add up to, and how that splits by category —
     * over every matching row, not the page, so the figure above a list is the
     * figure for the whole filter.
     *
     * @param  array<string, mixed>  $filters
     * @return array{count: int, total: string, by_category: array<int, array{category_id: int, name: string, count: int, total: string}>}
     */
    public function summary(array $filters): array;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PersonalExpense;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(PersonalExpense $expense, array $attributes): PersonalExpense;

    public function delete(PersonalExpense $expense): bool;
}
