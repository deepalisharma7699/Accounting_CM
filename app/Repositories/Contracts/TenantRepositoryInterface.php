<?php

namespace App\Repositories\Contracts;

use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TenantRepositoryInterface
{
    public function findById(int $id): ?Tenant;

    public function findBySlug(string $slug): ?Tenant;

    public function slugExists(string $slug): bool;

    public function gstinExists(string $gstin, ?int $exceptTenantId = null): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Tenant;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Tenant $tenant, array $attributes): Tenant;

    public function delete(Tenant $tenant): bool;

    public function userCount(Tenant $tenant): int;

    /**
     * @param  array{search?: string|null, status?: string|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
