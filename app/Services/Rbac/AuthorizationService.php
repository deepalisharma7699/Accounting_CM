<?php

namespace App\Services\Rbac;

use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Support\Facades\Cache;

/**
 * The single place where "may this user do this?" is answered.
 *
 * Grants are stored as (action, resource) pairs where either side may be the
 * wildcard "*". The seeded ADMIN role holds exactly one grant — `*` / `*` —
 * which is why it can do everything without enumerating permissions.
 */
class AuthorizationService
{
    public function __construct(
        private readonly PermissionRepositoryInterface $permissions,
    ) {}

    public function userHasPermission(User $user, string $action, string $resource): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        $roleId = $user->custom_role_id;

        if ($roleId === null) {
            return false;
        }

        return $this->roleHasPermission((int) $roleId, $action, $resource);
    }

    public function roleHasPermission(int $roleId, string $action, string $resource): bool
    {
        foreach ($this->grantsForRole($roleId) as $grant) {
            [$grantAction, $grantResource] = explode(':', $grant, 2);

            if ($this->matches($grantAction, $action) && $this->matches($grantResource, $resource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every requested pair must be satisfied (AND semantics).
     *
     * @param  array<int, array{0: string, 1: string}>  $required
     */
    public function userHasAllPermissions(User $user, array $required): bool
    {
        foreach ($required as [$action, $resource]) {
            if (! $this->userHasPermission($user, $action, $resource)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Flat list of "ACTION:RESOURCE" grants, cached per role.
     *
     * @return array<int, string>
     */
    public function grantsForRole(int $roleId): array
    {
        if (! (bool) config('rbac.cache.enabled', true)) {
            return $this->permissions->grantsForRole($roleId);
        }

        return Cache::remember(
            $this->cacheKey($roleId),
            (int) config('rbac.cache.ttl', 3600),
            fn () => $this->permissions->grantsForRole($roleId),
        );
    }

    /**
     * @return array<int, string>
     */
    public function grantsForUser(User $user): array
    {
        return $user->custom_role_id === null
            ? []
            : $this->grantsForRole((int) $user->custom_role_id);
    }

    /**
     * Must be called whenever a role's permission set changes, otherwise a
     * revoked permission would keep working until the cache expired.
     */
    public function flushRoleCache(Role|int $role): void
    {
        Cache::forget($this->cacheKey($role instanceof Role ? (int) $role->getKey() : $role));
    }

    private function matches(string $grantValue, string $requested): bool
    {
        return $grantValue === (string) config('rbac.wildcard', '*')
            || strcasecmp($grantValue, $requested) === 0;
    }

    private function cacheKey(int $roleId): string
    {
        return (string) config('rbac.cache.prefix', 'rbac:role_permissions:').$roleId;
    }
}
