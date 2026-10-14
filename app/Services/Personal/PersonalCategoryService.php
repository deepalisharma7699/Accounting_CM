<?php

namespace App\Services\Personal;

use App\Exceptions\Personal\PersonalExpenseException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\PersonalExpenseCategory;
use App\Repositories\Contracts\PersonalExpenseCategoryRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * The personal diary's category list.
 *
 * The uniqueness rule and the delete guard live here rather than in a form
 * request, so they hold wherever a category is written from.
 */
class PersonalCategoryService
{
    public function __construct(
        private readonly PersonalExpenseCategoryRepositoryInterface $categories,
        private readonly PersonalExpenseService $expenses,
    ) {}

    /**
     * Every category with an entry count against each — one query for the
     * counts — which is what makes "delete" or "archive" a decision somebody
     * can take.
     *
     * @return Collection<int, PersonalExpenseCategory>
     */
    public function withCounts(): Collection
    {
        $this->expenses->assertInstalled();

        $categories = $this->categories->all();
        $counts = $this->categories->expenseCounts($categories->pluck('id')->all());

        foreach ($categories as $category) {
            $category->setAttribute('expense_count', $counts[(int) $category->id] ?? 0);
        }

        return $categories;
    }

    public function find(int $id): PersonalExpenseCategory
    {
        $this->expenses->assertInstalled();

        return $this->categories->findById($id)
            ?? throw new ResourceNotFoundException('Category', $id);
    }

    /**
     * @param  array{name: string}  $data
     */
    public function create(array $data): PersonalExpenseCategory
    {
        $this->expenses->assertInstalled();

        $name = $this->normalise($data['name']);

        $this->assertNameAvailable($name);

        return $this->categories->create([
            'name' => $name,
            'is_active' => true,
            'display_order' => $this->categories->nextDisplayOrder(),
        ]);
    }

    /**
     * Rename, or archive and restore with `is_active`.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): PersonalExpenseCategory
    {
        $category = $this->find($id);
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $name = $this->normalise((string) $data['name']);

            if ($name !== $category->name) {
                $this->assertNameAvailable($name, $category->id);
                $attributes['name'] = $name;
            }
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        return $attributes === []
            ? $category
            : $this->categories->update($category, $attributes);
    }

    /**
     * Only a category nothing is filed under. The foreign key would refuse the
     * rest anyway; this answers with a sentence instead of an integrity error.
     */
    public function delete(int $id): void
    {
        $category = $this->find($id);
        $count = $this->categories->expenseCounts([$category->id])[$category->id] ?? 0;

        if ($count > 0) {
            throw PersonalExpenseException::categoryInUse($category->id, $category->name, $count);
        }

        $this->categories->delete($category);
    }

    private function normalise(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function assertNameAvailable(string $name, ?int $exceptId = null): void
    {
        if (! $this->categories->nameExists($name, $exceptId)) {
            return;
        }

        throw PersonalExpenseException::nameTaken($name);
    }
}
