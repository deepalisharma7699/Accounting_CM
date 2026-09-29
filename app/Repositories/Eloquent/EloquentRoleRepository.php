<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Roles are tenant-based, and the two scopes are two separate lists.
 *
 * A NULL `tenant_id` is a platform role — what the platform administrator's own
 * panel runs on. Anything else belongs to exactly one workshop, which is given
 * its own set at provisioning (see {@see \App\Services\Rbac\RoleProvisioner}).
 * Neither scope can see the other: a workshop lists its own roles and nothing
 * else, and the platform lists its own and nothing else. That is deliberate and
 * it is the point of the split — the platform's role card used to *be* the
 * workshops' role list, so deleting a role from one emptied it from every
 * workshop at once.
 *
 * The platform reaches a workshop's roles the way it reaches its users: through
 * `/api/v1/tenants/{tenant}/roles`, where {@see \App\Http\Middleware\ActAsTenant}
 * re-points the context at that workshop and every rule below then applies
 * unchanged.
 *
 * `roles` is scoped here rather than by a global scope, for the reason `users`
 * is: an ordinary global scope would also filter the `customRole` relation the
 * authorization path loads, and a user whose role vanished from under them
 * would silently lose every grant. Keeping the filter in one private method
 * leaves one place to audit.
 */
class EloquentRoleRepository implements RoleRepositoryInterface
{
    public function __construct(private readonly TenantContext $context) {}

    public function findById(int $id): ?Role
    {
        return $this->visible(Role::with(['permissions', 'tenant']))->find($id);
    }

    public function nameExists(string $name, ?int $exceptRoleId = null): bool
    {
        // Soft-deleted roles still hold their name/slug (both columns are
        // uniquely indexed), so withTrashed() is required to avoid a
        // duplicate-key error on insert.
        //
        // Scoped to exactly the scope the new role would land in, which is
        // exactly what the unique indexes cover: they are built on the
        // generated `scope_key` (`coalesce(tenant_id, 0)`), so two workshops
        // may each have a "Cashier" and the platform may have one too.
        $tenantId = $this->context->current();

        return Role::withTrashed()
            ->where(function ($query) use ($name) {
                $query->where('name', $name)->orWhere('slug', Role::slugFor($name));
            })
            ->when(
                $tenantId === null,
                fn ($query) => $query->whereNull('tenant_id'),
                fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->when($exceptRoleId !== null, fn ($query) => $query->whereKeyNot($exceptRoleId))
            ->exists();
    }

    public function all(): Collection
    {
        return $this->visible(Role::with(['permissions', 'tenant']))->orderBy('name')->get();
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->visible(Role::query())
            ->with(['permissions', 'tenant'])
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
        // Stamped from the context, never from the caller: a workshop cannot
        // plant a role in somebody else's list, and a platform request (no
        // tenant) makes a platform role.
        $attributes['tenant_id'] = $this->context->current();

        return Role::create($attributes)->load('tenant');
    }

    public function update(Role $role, array $attributes): Role
    {
        // Write-once, like a user's: moving a role between workshops would hand
        // its grants to people who were never meant to hold them.
        unset($attributes['tenant_id']);

        $role->fill($attributes)->save();

        return $role->fresh(['permissions', 'tenant']);
    }

    public function delete(Role $role): bool
    {
        return (bool) $role->delete();
    }

    public function syncPermissions(Role $role, array $permissionIds): Role
    {
        $role->permissions()->sync($permissionIds);

        return $role->fresh(['permissions', 'tenant']);
    }

    public function countUsers(Role $role): int
    {
        return User::where('custom_role_id', $role->id)->count();
    }

    /**
     * What the current context may see: its own scope, and only its own.
     *
     * A workshop sees the roles belonging to that workshop. A platform user —
     * or the platform administrator acting on their own panel — sees the
     * platform's, which is where ADMIN lives and where a workshop never had any
     * business looking.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    private function visible(Builder $query): Builder
    {
        if ($this->context->isUnscoped()) {
            return $query;
        }

        if (! $this->context->isResolved()) {
            // Fail closed, as the users repository does.
            throw MissingTenantContextException::for(Role::class);
        }

        $tenantId = $this->context->current();

        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }
}
