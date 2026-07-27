<?php

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\TokenService;

trait InteractsWithAuthModule
{
    /**
     * A role holding exactly the given grants.
     *
     * @param  array<int, array{0: string, 1: string}>  $grants
     */
    protected function roleWith(array $grants, string $name = 'Test Role', bool $system = false): Role
    {
        $role = Role::create([
            'name' => $name,
            'slug' => Role::slugFor($name),
            'description' => null,
            'is_system_role' => $system,
        ]);

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
