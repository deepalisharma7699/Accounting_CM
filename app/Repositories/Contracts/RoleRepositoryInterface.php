<?php

namespace App\Repositories\Contracts;

use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface RoleRepositoryInterface
{
    /**
     * A role, if the current context may see it. Another workshop's role is
     * simply not found — never "forbidden", which would confirm it exists.
     */
    public function findById(int $id): ?Role;

    /**
     * Whether the name (or its slug) is taken in the scope a new role would
     * land in — this workshop's roles, or the platform's. The two scopes are
     * independent, exactly as the unique indexes are, so a workshop may name a
     * role whatever the platform already calls one of its own.
     */
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
