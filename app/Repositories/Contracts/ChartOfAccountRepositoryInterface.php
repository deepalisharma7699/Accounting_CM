<?php

namespace App\Repositories\Contracts;

use App\Enums\SystemAccount;
use App\Models\ChartOfAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Every method here is tenant-scoped by the global scope on ChartOfAccount —
 * there is no cross-tenant family, unlike users. The chart is pure tenant data.
 */
interface ChartOfAccountRepositoryInterface
{
    public function findById(int $id): ?ChartOfAccount;

    public function findByCode(string $code): ?ChartOfAccount;

    /**
     * Resolve one of the engine's known accounts. The hottest read in the
     * application once posting exists: every transaction resolves several.
     */
    public function findBySystemKey(SystemAccount $account): ?ChartOfAccount;

    public function codeExists(string $code, ?int $exceptId = null): bool;

    public function nameExists(string $name, ?int $exceptId = null): bool;

    /**
     * The whole chart, ordered for display. Small by nature — fifteen seeded
     * accounts plus whatever the workshop added — so it is fetched whole for
     * pickers rather than paginated.
     *
     * @return Collection<int, ChartOfAccount>
     */
    public function all(bool $activeOnly = false): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ChartOfAccount;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ChartOfAccount $account, array $attributes): ChartOfAccount;

    /**
     * @param  array{search?: string|null, type?: string|null, is_active?: bool|null, is_system?: bool|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, ChartOfAccount>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
