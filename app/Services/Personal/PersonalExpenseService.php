<?php

namespace App\Services\Personal;

use App\Exceptions\Personal\PersonalExpenseException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\PersonalExpense;
use App\Repositories\Contracts\PersonalExpenseCategoryRepositoryInterface;
use App\Repositories\Contracts\PersonalExpenseRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's personal diary — what they spent out of their own pocket.
 *
 * Nothing here touches the books, and that is the design rather than a gap: the
 * workshop's money never moved, so there is no posting, no ledger entry and no
 * report that reads these rows. An entry is a diary line, edited and deleted
 * freely.
 *
 * §4.6: the tables arrive by a manual SQL step, so the code can be deployed
 * first. {@see isInstalled()} is the one place that is decided, and every entry
 * point refuses cleanly while it answers no.
 */
class PersonalExpenseService
{
    private ?bool $installed = null;

    public function __construct(
        private readonly PersonalExpenseRepositoryInterface $expenses,
        private readonly PersonalExpenseCategoryRepositoryInterface $categories,
    ) {}

    public function isInstalled(): bool
    {
        return $this->installed ??= Schema::hasTable('personal_expenses');
    }

    public function assertInstalled(): void
    {
        if (! $this->isInstalled()) {
            throw PersonalExpenseException::notInstalled();
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PersonalExpense>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $this->assertInstalled();

        return $this->expenses->paginate($filters, $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{count: int, total: string, by_category: array<int, array<string, mixed>>}
     */
    public function summary(array $filters): array
    {
        $this->assertInstalled();

        return $this->expenses->summary($filters);
    }

    public function find(int $id): PersonalExpense
    {
        $this->assertInstalled();

        return $this->expenses->findById($id)
            ?? throw new ResourceNotFoundException('Personal expense', $id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $authorId): PersonalExpense
    {
        $this->assertInstalled();
        $this->assertCategoryUsable((int) $data['category_id']);

        $expense = $this->expenses->create([
            'date' => $data['date'],
            'category_id' => (int) $data['category_id'],
            'amount' => $data['amount'],
            'paid_by' => $data['paid_by'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $authorId,
        ]);

        return $this->find($expense->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): PersonalExpense
    {
        $expense = $this->find($id);

        // An archived category may stay on the entries already filed under it;
        // it may not be newly chosen.
        if (array_key_exists('category_id', $data) && (int) $data['category_id'] !== $expense->category_id) {
            $this->assertCategoryUsable((int) $data['category_id']);
        }

        return $this->expenses->update($expense, array_intersect_key($data, array_flip([
            'date', 'category_id', 'amount', 'paid_by', 'notes',
        ])));
    }

    public function delete(int $id): void
    {
        $this->expenses->delete($this->find($id));
    }

    /**
     * Resolved through the tenant-scoped repository, so another workshop's
     * category id is "not found" rather than adopted.
     */
    private function assertCategoryUsable(int $categoryId): void
    {
        $category = $this->categories->findById($categoryId);

        if ($category === null) {
            throw PersonalExpenseException::unknownCategory();
        }

        if (! $category->is_active) {
            throw PersonalExpenseException::categoryArchived($category->name);
        }
    }
}
