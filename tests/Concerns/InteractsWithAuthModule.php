<?php

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\TokenService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

trait InteractsWithAuthModule
{
    /**
     * Seed the real permission catalogue and system roles.
     *
     * Needed by anything that depends on a seeded role existing by name —
     * workshop sign-up, which attaches OWNER to the first user, above all.
     * Most tests build a bespoke role with roleWith() instead and can skip it.
     */
    protected function seedRoleCatalogue(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    /**
     * A role holding exactly the given grants.
     *
     * @param  array<int, array{0: string, 1: string}>  $grants
     */
    protected function roleWith(array $grants, string $name = 'Test Role', bool $system = false): Role
    {
        // Keyed on the slug rather than created outright, so a test may both
        // seed the real catalogue and ask for a role the seeder already made
        // — ADMIN, most often — without tripping the unique index.
        $role = Role::updateOrCreate(
            ['slug' => Role::slugFor($name)],
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
