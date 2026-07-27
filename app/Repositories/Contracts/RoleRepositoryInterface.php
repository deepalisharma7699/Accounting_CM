<?php

namespace App\Repositories\Contracts;

use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface RoleRepositoryInterface
{
    public function findById(int $id): ?Role;

    public function findBySlug(string $slug): ?Role;

    public function nameExists(string $name, ?int $exceptRoleId = null): bool;

    /**
     * @return Collection<int, Role>
     */
    public function all(): Collection;

    /**
     * @param  array{search?: string|null, system?: bool|null}  $filters
     * @return LengthAwarePaginator<int, Role>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Role;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Role $role, array $attributes): Role;

    public function delete(Role $role): bool;

    /**
     * @param  array<int, int>  $permissionIds
     */
    public function syncPermissions(Role $role, array $permissionIds): Role;

    public function countUsers(Role $role): int;
}
