<?php

namespace App\Repositories\Eloquent;

use App\Models\PersonalExpense;
use App\Models\PersonalExpenseCategory;
use App\Repositories\Contracts\PersonalExpenseCategoryRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentPersonalExpenseCategoryRepository implements PersonalExpenseCategoryRepositoryInterface
{
    public function findById(int $id): ?PersonalExpenseCategory
    {
        return PersonalExpenseCategory::find($id);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return PersonalExpenseCategory::where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function all(bool $activeOnly = false): Collection
    {
        return PersonalExpenseCategory::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    public function nextDisplayOrder(): int
    {
        return (int) PersonalExpenseCategory::max('display_order') + 1;
    }

    public function create(array $attributes): PersonalExpenseCategory
    {
        return PersonalExpenseCategory::create($attributes);
    }

    public function update(PersonalExpenseCategory $category, array $attributes): PersonalExpenseCategory
    {
        $category->fill($attributes)->save();

        return $category->refresh();
    }

    public function delete(PersonalExpenseCategory $category): bool
    {
        return (bool) $category->delete();
    }

    public function expenseCounts(array $categoryIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        $counts = array_fill_keys($ids, 0);

        if ($ids === []) {
            return $counts;
        }

        PersonalExpense::query()
            ->whereIn('category_id', $ids)
            ->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) as entries')
            ->get()
            ->each(function ($row) use (&$counts): void {
                $counts[(int) $row->category_id] = (int) $row->entries;
            });

        return $counts;
    }
}
