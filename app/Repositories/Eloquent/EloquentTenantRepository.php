<?php

namespace App\Repositories\Eloquent;

use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentTenantRepository implements TenantRepositoryInterface
{
    /**
     * Columns a client is allowed to sort by. Anything else falls back to the
     * default, which keeps user input out of the ORDER BY clause.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['id', 'name', 'slug', 'status', 'created_at'];

    public function __construct(private readonly TenantContext $context) {}

    public function findById(int $id): ?Tenant
    {
        return Tenant::find($id);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return Tenant::where('slug', $slug)->first();
    }

    public function slugExists(string $slug): bool
    {
        // Soft-deleted tenants keep their row and the unique index covers
        // them, so a trashed slug is still taken.
        return Tenant::withTrashed()->where('slug', $slug)->exists();
    }

    public function gstinExists(string $gstin, ?int $exceptTenantId = null): bool
    {
        return Tenant::withTrashed()
            ->where('gstin', $gstin)
            ->when($exceptTenantId !== null, fn ($query) => $query->whereKeyNot($exceptTenantId))
            ->exists();
    }

    public function create(array $attributes): Tenant
    {
        return Tenant::create($attributes);
    }

    public function update(Tenant $tenant, array $attributes): Tenant
    {
        $tenant->fill($attributes)->save();

        return $tenant->fresh();
    }

    public function delete(Tenant $tenant): bool
    {
        return (bool) $tenant->delete();
    }

    /**
     * Users belong to a tenant other than the caller's, so this count has to
     * cross the isolation boundary deliberately. It returns a number, never
     * rows, so nothing about the other tenant's users escapes.
     */
    public function userCount(Tenant $tenant): int
    {
        return $this->context->runWithoutScope(
            fn () => User::where('tenant_id', $tenant->getKey())->count()
        );
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return Tenant::query()
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where(function ($query) use ($filters) {
                    $term = '%'.$filters['search'].'%';
                    $query->where('name', 'like', $term)
                        ->orWhere('slug', 'like', $term)
                        ->orWhere('gstin', 'like', $term);
                })
            )
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();
    }
}
