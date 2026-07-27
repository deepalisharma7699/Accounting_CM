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
