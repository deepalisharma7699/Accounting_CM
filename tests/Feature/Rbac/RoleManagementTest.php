<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use InteractsWithAuthModule, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->withRole($this->adminRole())->create();
    }

    public function test_an_admin_can_create_a_custom_role_with_permissions(): void
    {
        $read = Permission::create(['action' => 'READ', 'resource' => 'INVOICES']);
        $write = Permission::create(['action' => 'WRITE', 'resource' => 'INVOICES']);

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Branch Accountant',
            'description' => 'Handles branch invoicing.',
            'permission_ids' => [$read->id, $write->id],
        ], $this->authHeader($this->admin));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Branch Accountant')
            ->assertJsonPath('data.slug', 'BRANCH_ACCOUNTANT')
            ->assertJsonPath('data.is_system_role', false)
            ->assertJsonCount(2, 'data.permissions');

        $this->assertDatabaseHas('roles', ['slug' => 'BRANCH_ACCOUNTANT', 'is_system_role' => false]);
        $this->assertDatabaseCount('role_permission', 3); // 2 here + the admin wildcard
    }

    public function test_it_rejects_a_duplicate_role_name(): void
    {
        Role::factory()->create(['name' => 'Auditor', 'slug' => Role::slugFor('Auditor')]);

        $this->postJson('/api/v1/roles', ['name' => 'Auditor'], $this->authHeader($this->admin))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RBAC_ROLE_EXISTS');
    }

    public function test_it_rejects_unknown_permission_ids(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'Ghost Role',
            'permission_ids' => [9999],
        ], $this->authHeader($this->admin))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_an_admin_can_update_a_custom_role(): void
    {
        $role = Role::factory()->create(['name' => 'Auditor', 'slug' => Role::slugFor('Auditor')]);

        $this->patchJson("/api/v1/roles/{$role->id}", [
            'name' => 'Senior Auditor',
            'description' => 'Reviews everything.',
        ], $this->authHeader($this->admin))
            ->assertOk()
            ->assertJsonPath('data.name', 'Senior Auditor')
            ->assertJsonPath('data.slug', 'SENIOR_AUDITOR');
    }

    public function test_the_permission_set_can_be_replaced_wholesale(): void
    {
        $role = $this->roleWith([['READ', 'INVOICES']], 'Auditor');
        $write = Permission::firstOrCreate(['action' => 'WRITE', 'resource' => 'INVOICES']);

        $this->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permission_ids' => [$write->id],
        ], $this->authHeader($this->admin))
            ->assertOk()
            ->assertJsonCount(1, 'data.permissions')
            ->assertJsonPath('data.permissions.0.key', 'WRITE:INVOICES');
    }

    public function test_a_system_role_cannot_be_updated_or_deleted(): void
    {
        $adminRole = Role::where('is_system_role', true)->firstOrFail();

        $this->patchJson("/api/v1/roles/{$adminRole->id}", ['name' => 'Hijacked'], $this->authHeader($this->admin))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'RBAC_SYSTEM_ROLE_IMMUTABLE');

        $this->deleteJson("/api/v1/roles/{$adminRole->id}", [], $this->authHeader($this->admin))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'RBAC_SYSTEM_ROLE_IMMUTABLE');

        $this->assertDatabaseHas('roles', ['id' => $adminRole->id, 'name' => 'ADMIN']);
    }

    public function test_a_role_still_assigned_to_users_cannot_be_deleted(): void
    {
        $role = Role::factory()->create();
        User::factory()->withRole($role)->create();

        $this->deleteJson("/api/v1/roles/{$role->id}", [], $this->authHeader($this->admin))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RBAC_ROLE_IN_USE')
            ->assertJsonPath('error.details.assigned_users', 1);
    }

    public function test_an_unassigned_custom_role_can_be_deleted(): void
    {
        $role = Role::factory()->create();

        $this->deleteJson("/api/v1/roles/{$role->id}", [], $this->authHeader($this->admin))
            ->assertOk();

        $this->assertSoftDeleted('roles', ['id' => $role->id]);
    }

    public function test_a_missing_role_returns_404(): void
    {
        $this->getJson('/api/v1/roles/9999', $this->authHeader($this->admin))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_a_user_without_role_permissions_is_forbidden(): void
    {
        $user = $this->userWithGrants([['READ', 'USERS']], 'Viewer');

        $this->getJson('/api/v1/roles', $this->authHeader($user))->assertStatus(403);
        $this->postJson('/api/v1/roles', ['name' => 'Nope'], $this->authHeader($user))->assertStatus(403);
    }

    public function test_roles_are_listed_with_pagination_metadata(): void
    {
        Role::factory()->count(3)->create();

        $this->getJson('/api/v1/roles?per_page=2', $this->authHeader($this->admin))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 4); // 3 + ADMIN
    }
}
