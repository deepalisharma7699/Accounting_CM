<?php

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\TokenService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

trait InteractsWithAuthModule
{
    /**
     * Seed the real permission catalogue and the platform's system roles.
     *
     * The catalogue is the half most tests want: a workshop's own roles are
     * stamped out by RoleProvisioner (which TenantFactory runs) and come out
     * with no grants at all unless the permissions exist first. RoleSeeder
     * itself only creates ADMIN, which is the platform's.
     *
     * Most tests build a bespoke role with roleWith() instead and can skip it.
     */
    protected function seedRoleCatalogue(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    /**
     * A role holding exactly the given grants.
     *
     * `$tenant` decides the scope, and it matters: a role with no tenant is a
     * *platform* role, which a workshop can neither see nor be given. A role for
     * somebody inside a workshop has to be that workshop's — see
     * {@see \Tests\Concerns\InteractsWithTenancy::tenantWithUser()}, which
     * passes one.
     *
     * @param  array<int, array{0: string, 1: string}>  $grants
     */
    protected function roleWith(
        array $grants,
        string $name = 'Test Role',
        bool $system = false,
        Tenant|int|null $tenant = null,
    ): Role {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;

        // Keyed on the scope *and* the slug rather than created outright, so a
        // test may both seed the real catalogue and ask for a role that already
        // exists — ADMIN, most often, or one of a factory workshop's four —
        // without tripping the per-scope unique index.
        $role = Role::updateOrCreate(
            ['tenant_id' => $tenantId, 'slug' => Role::slugFor($name)],
            [
                'name' => $name,
                'description' => null,
                'is_system_role' => $system,
            ]
        );

        $ids = [];

        foreach ($grants as [$action, $resource]) {
            $ids[] = Permission::firstOrCreate(
                ['action' => $action, 'resource' => $resource],
                ['description' => "{$action} on {$resource}."]
            )->id;
        }

        $role->permissions()->sync($ids);

        return $role->fresh(['permissions']);
    }

    /**
     * The seeded-style ADMIN role: one wildcard grant, flagged as a system role.
     */
    protected function adminRole(): Role
    {
        return $this->roleWith([['*', '*']], 'ADMIN', system: true);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $grants
     */
    protected function userWithGrants(array $grants, string $roleName = 'Test Role'): User
    {
        return User::factory()->withRole($this->roleWith($grants, $roleName))->create();
    }

    /**
     * Authorization header for a freshly minted access token.
     *
     * @return array<string, string>
     */
    protected function authHeader(User $user): array
    {
        $token = app(TokenService::class)->issueAccessToken($user->load('customRole'));

        return ['Authorization' => "Bearer {$token}"];
    }

    protected function refreshCookieName(): string
    {
        return (string) config('jwt.cookie.name');
    }
}
