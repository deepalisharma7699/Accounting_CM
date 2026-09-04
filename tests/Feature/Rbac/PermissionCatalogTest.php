<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    use InteractsWithAuthModule, RefreshDatabase;

    public function test_the_seeder_creates_the_wildcard_and_resource_permissions(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->assertDatabaseHas('permissions', ['action' => '*', 'resource' => '*']);
        $this->assertDatabaseHas('permissions', ['action' => 'READ', 'resource' => 'USERS']);
        $this->assertDatabaseHas('permissions', ['action' => 'DELETE', 'resource' => 'ROLES']);

        // Re-running must not duplicate rows.
        $before = Permission::count();
        $this->seed(PermissionSeeder::class);
        $this->assertSame($before, Permission::count());
    }

    public function test_an_authorised_user_can_list_the_catalogue(): void
    {
        $this->seed(PermissionSeeder::class);

        $user = $this->userWithGrants([['READ', 'PERMISSIONS']], 'Auditor');

        $this->getJson('/api/v1/permissions', $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'action', 'resource', 'key', 'description']]]);
    }

    /**
     * `resource` is the resource's *name*, not the model behind the grant.
     *
     * A structure assertion cannot catch this: `$this->resource` on a
     * JsonResource is the wrapped model rather than a forwarded attribute, so
     * the field was emitting the whole Permission — pivot row and timestamps
     * included — and still satisfied `assertJsonStructure`. What broke on it was
     * every client that groups grants by resource or looks for the `*`/`*`
     * wildcard, because neither an object nor its identity is a name.
     */
    public function test_a_permission_states_its_resource_as_a_name(): void
    {
        $this->seed(PermissionSeeder::class);

        $user = $this->userWithGrants([['READ', 'PERMISSIONS']], 'Auditor');

        $payload = $this->getJson('/api/v1/permissions', $this->authHeader($user))
            ->assertOk()
            ->json('data');

        foreach ($payload as $permission) {
            $this->assertIsString($permission['resource'], 'A permission leaked a model where its resource name belongs.');
            $this->assertIsString($permission['action']);
            $this->assertSame($permission['action'].':'.$permission['resource'], $permission['key']);
            $this->assertArrayNotHasKey('pivot', $permission);
        }

        $this->assertContains('*', array_column($payload, 'resource'), 'The wildcard grant must be recognisable.');
    }

    /** The same field, reached through a role — which is where the UI reads it. */
    public function test_a_roles_permissions_state_their_resource_as_a_name(): void
    {
        $this->seed(PermissionSeeder::class);

        $user = $this->userWithGrants([['READ', 'ROLES'], ['READ', 'PERMISSIONS']], 'Auditor');

        $roles = $this->getJson('/api/v1/roles', $this->authHeader($user))
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($roles);

        foreach ($roles as $role) {
            foreach ($role['permissions'] ?? [] as $permission) {
                $this->assertIsString($permission['resource']);
                $this->assertArrayNotHasKey('pivot', $permission);
            }
        }
    }

    public function test_the_catalogue_can_be_grouped_by_resource(): void
    {
        $this->seed(PermissionSeeder::class);

        $user = $this->userWithGrants([['READ', 'PERMISSIONS']], 'Auditor');

        $this->getJson('/api/v1/permissions?grouped=1', $this->authHeader($user))
            ->assertOk()
            ->assertJsonStructure(['data' => ['USERS' => [['id', 'action', 'description']]]]);
    }

    public function test_a_user_without_the_grant_cannot_list_the_catalogue(): void
    {
        $this->seed(PermissionSeeder::class);

        $user = User::factory()->create();

        $this->getJson('/api/v1/permissions', $this->authHeader($user))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'AUTH_FORBIDDEN');
    }

    public function test_the_full_seed_creates_an_admin_that_can_reach_every_endpoint(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($admin->customRole->isSystemRole());
        $this->assertTrue($admin->hasPermissionTo('DELETE', 'ANYTHING'));

        $this->getJson('/api/v1/users', $this->authHeader($admin))->assertOk();
        $this->getJson('/api/v1/roles', $this->authHeader($admin))->assertOk();
        $this->getJson('/api/v1/permissions', $this->authHeader($admin))->assertOk();
    }
}
