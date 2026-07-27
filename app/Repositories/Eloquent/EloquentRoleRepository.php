<?php

namespace App\Repositories\Eloquent;

use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EloquentRoleRepository implements RoleRepositoryInterface
{
    public function findById(int $id): ?Role
    {
        return Role::with('permissions')->find($id);
    }

    public function findBySlug(string $slug): ?Role
    {
        return Role::with('permissions')->where('slug', $slug)->first();
    }

    public function nameExists(string $name, ?int $exceptRoleId = null): bool
    {
        // Soft-deleted roles still hold their name/slug (both columns are
        // uniquely indexed), so withTrashed() is required to avoid a
        // duplicate-key error on insert.
        return Role::withTrashed()
            ->where(function ($query) use ($name) {
                $query->where('name', $name)->orWhere('slug', Role::slugFor($name));
            })
            ->when($exceptRoleId !== null, fn ($query) => $query->whereKeyNot($exceptRoleId))
            ->exists();
    }

    public function all(): Collection
    {
        return Role::with('permissions')->orderBy('name')->get();
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Role::query()
            ->with('permissions')
            ->withCount('users')
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->where('name', 'like', '%'.$filters['search'].'%')
            )
            ->when(
                isset($filters['system']),
                fn ($query) => $query->where('is_system_role', (bool) $filters['system'])
            )
            ->orderByDesc('is_system_role')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $attributes): Role
    {
        return Role::create($attributes);
    }

    public function update(Role $role, array $attributes): Role
    {
        $role->fill($attributes)->save();

        return $role->fresh(['permissions']);
    }

    public function delete(Role $role): bool
    {
        return (bool) $role->delete();
    }

    public function syncPermissions(Role $role, array $permissionIds): Role
    {
        $role->permissions()->sync($permissionIds);

        return $role->fresh(['permissions']);
    }

    public function countUsers(Role $role): int
    {
        return User::where('custom_role_id', $role->id)->count();
    }
}
