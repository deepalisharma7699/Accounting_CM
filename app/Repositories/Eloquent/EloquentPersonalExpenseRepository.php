<?php

namespace App\Repositories\Eloquent;

use App\Models\PersonalExpense;
use App\Repositories\Contracts\PersonalExpenseRepositoryInterface;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentPersonalExpenseRepository implements PersonalExpenseRepositoryInterface
{
    public function findById(int $id): ?PersonalExpense
    {
        return PersonalExpense::with(['category:id,name', 'author:id,name'])->find($id);
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filtered($filters)
            ->with(['category:id,name', 'author:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function summary(array $filters): array
    {
        $rows = $this->filtered($filters)
            ->join('personal_expense_categories as c', 'c.id', '=', 'personal_expenses.category_id')
            ->groupBy('personal_expenses.category_id', 'c.name')
            ->selectRaw('personal_expenses.category_id, c.name, COUNT(*) as entries, SUM(personal_expenses.amount) as total')
            ->orderByDesc('total')
            ->get();

        $count = 0;
        $total = Money::zero();
        $byCategory = [];

        foreach ($rows as $row) {
            // Through Money, never float addition: the column is DECIMAL and
            // comes back as a string, and summing strings as floats is how a
            // total drifts from its rows.
            $rowTotal = Money::of((string) $row->total);

            $count += (int) $row->entries;
            $total = $total->plus($rowTotal);

            $byCategory[] = [
                'category_id' => (int) $row->category_id,
                'name' => (string) $row->name,
                'count' => (int) $row->entries,
                'total' => $rowTotal->amount(),
            ];
        }

        return [
            'count' => $count,
            'total' => $total->amount(),
            'by_category' => $byCategory,
        ];
    }

    public function create(array $attributes): PersonalExpense
    {
        return PersonalExpense::create($attributes);
    }

    public function update(PersonalExpense $expense, array $attributes): PersonalExpense
    {
        $expense->fill($attributes)->save();

        return $expense->refresh()->load(['category:id,name', 'author:id,name']);
    }

    public function delete(PersonalExpense $expense): bool
    {
        return (bool) $expense->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PersonalExpense>
     */
    private function filtered(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return PersonalExpense::query()
            ->when($search !== '', fn (Builder $query) => $query->where(
                'personal_expenses.notes', 'like', '%'.addcslashes($search, '%_\\').'%'
            ))
            ->when($filters['category_id'] ?? null, fn (Builder $query, $id) => $query->where('personal_expenses.category_id', (int) $id))
            ->when($filters['paid_by'] ?? null, fn (Builder $query, $method) => $query->where('personal_expenses.paid_by', $method))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->where('personal_expenses.date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->where('personal_expenses.date', '<=', $to));
    }
}
