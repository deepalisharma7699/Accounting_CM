<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Roles are tenant-based, and the two scopes are two separate lists.
 *
 * A workshop writes and sees roles of its own — never another workshop's, and
 * never the platform's either. The platform's own panel sees the platform's
 * roles and reaches a workshop's through `/tenants/{tenant}/roles`, covered by
 * {@see \Tests\Feature\Tenancy\PlatformWorkshopAccessTest}.
 */
class RoleTenancyTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $ownerA;

    private User $ownerB;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalogue();

        // Each workshop is given its own four by RoleProvisioner, which the
        // factory runs — so each owner holds their *own* workshop's OWNER.
        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
        $this->ownerA = User::factory()->forTenant($this->tenantA)->withRole($this->ownerRoleOf($this->tenantA))->create();
        $this->ownerB = User::factory()->forTenant($this->tenantB)->withRole($this->ownerRoleOf($this->tenantB))->create();
        $this->platformAdmin = User::factory()->withRole($this->adminRole())->create();
    }

    private function ownerRoleOf(Tenant $tenant): Role
    {
        return Role::where('tenant_id', $tenant->id)->where('slug', 'OWNER')->firstOrFail();
    }

    private function grant(string $action, string $resource): int
    {
        return Permission::where('action', $action)->where('resource', $resource)->firstOrFail()->id;
    }

    /**
     * @param  array<int, int>  $permissionIds
     */
    private function createRole(User $as, string $name, array $permissionIds = []): int
    {
        return (int) $this->postJson('/api/v1/roles', [
            'name' => $name,
            'permission_ids' => $permissionIds,
        ], $this->authHeader($as))->assertCreated()->json('data.id');
    }

    #[Test]
    public function a_workshop_owner_creates_a_role_that_belongs_to_their_workshop(): void
    {
        $this->postJson('/api/v1/roles', [
            'name' => 'Cashier',
            'permission_ids' => [$this->grant('READ', 'ITEMS')],
        ], $this->authHeader($this->ownerA))
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', $this->tenantA->id)
            ->assertJsonPath('data.scope', 'workshop')
            ->assertJsonPath('data.editable', true);

        $this->assertDatabaseHas('roles', ['slug' => 'CASHIER', 'tenant_id' => $this->tenantA->id]);
    }

    #[Test]
    public function a_workshop_cannot_see_read_change_or_delete_another_workshops_role(): void
    {
        $id = $this->createRole($this->ownerA, 'Cashier', [$this->grant('READ', 'ITEMS')]);

        $names = collect($this->getJson('/api/v1/roles?per_page=100', $this->authHeader($this->ownerB))
            ->assertOk()->json('data'))->pluck('name');

        $this->assertNotContains('Cashier', $names);

        // Not found rather than forbidden: forbidden would confirm it exists.
        $this->getJson("/api/v1/roles/{$id}", $this->authHeader($this->ownerB))->assertNotFound();
        $this->patchJson("/api/v1/roles/{$id}", ['name' => 'Hijacked'], $this->authHeader($this->ownerB))->assertNotFound();
        $this->deleteJson("/api/v1/roles/{$id}", [], $this->authHeader($this->ownerB))->assertNotFound();
        $this->putJson("/api/v1/roles/{$id}/permissions", ['permission_ids' => []], $this->authHeader($this->ownerB))->assertNotFound();
    }

    #[Test]
    public function two_workshops_may_each_have_a_role_of_the_same_name(): void
    {
        $this->createRole($this->ownerA, 'Cashier');
        $this->createRole($this->ownerB, 'Cashier');

        $this->assertSame(2, Role::where('slug', 'CASHIER')->count());
    }

    #[Test]
    public function a_workshop_role_cannot_take_the_name_of_one_the_workshop_already_has(): void
    {
        $this->postJson('/api/v1/roles', ['name' => 'Owner'], $this->authHeader($this->ownerA))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RBAC_ROLE_EXISTS');
    }

    #[Test]
    public function a_new_workshop_is_given_its_own_four_default_roles(): void
    {
        $names = collect($this->getJson('/api/v1/roles?per_page=100', $this->authHeader($this->ownerA))
            ->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing(
            ['OWNER', 'MANAGER', 'ACCOUNTANT', 'DATA_ENTRY'],
            $names->pluck('name')->all(),
        );

        // Its own rows, not shared ones, and editable because that is the point
        // of a workshop owning its roles.
        $names->each(function (array $role) {
            $this->assertSame($this->tenantA->id, $role['tenant_id']);
            $this->assertSame('workshop', $role['scope']);
            $this->assertFalse($role['is_system_role']);
            $this->assertTrue($role['editable']);
        });
    }

    #[Test]
    public function a_workshop_never_sees_a_platform_role(): void
    {
        $shared = Role::create([
            'tenant_id' => null,
            'name' => 'Platform Support',
            'slug' => 'PLATFORM_SUPPORT',
            'is_system_role' => false,
        ]);

        $names = collect($this->getJson('/api/v1/roles?per_page=100', $this->authHeader($this->ownerA))
            ->assertOk()->json('data'))->pluck('name');

        $this->assertNotContains('ADMIN', $names);
        $this->assertNotContains('Platform Support', $names);

        // Not found rather than forbidden, exactly as another workshop's is.
        $admin = Role::whereNull('tenant_id')->where('slug', 'ADMIN')->firstOrFail();

        foreach ([$admin->id, $shared->id] as $id) {
            $this->getJson("/api/v1/roles/{$id}", $this->authHeader($this->ownerA))->assertNotFound();
            $this->patchJson("/api/v1/roles/{$id}", ['name' => 'Renamed'], $this->authHeader($this->ownerA))->assertNotFound();
            $this->deleteJson("/api/v1/roles/{$id}", [], $this->authHeader($this->ownerA))->assertNotFound();
            $this->putJson("/api/v1/roles/{$id}/permissions", ['permission_ids' => []], $this->authHeader($this->ownerA))->assertNotFound();
        }
    }

    #[Test]
    public function the_platform_sees_its_own_roles_and_no_workshops(): void
    {
        $this->createRole($this->ownerA, 'Cashier A');

        $names = collect($this->getJson('/api/v1/roles?per_page=100', $this->authHeader($this->platformAdmin))
            ->assertOk()->json('data'))->pluck('name');

        // ADMIN is the platform's, and it is the only role seeded there.
        $this->assertContains('ADMIN', $names);
        $this->assertNotContains('Cashier A', $names);
        $this->assertNotContains('OWNER', $names);
        $this->assertNotContains('DATA_ENTRY', $names);
    }

    #[Test]
    public function a_workshop_can_change_and_delete_its_own_default_role(): void
    {
        $manager = Role::where('tenant_id', $this->tenantA->id)->where('slug', 'MANAGER')->firstOrFail();

        $this->patchJson("/api/v1/roles/{$manager->id}", ['name' => 'Floor Manager'], $this->authHeader($this->ownerA))
            ->assertOk()
            ->assertJsonPath('data.name', 'Floor Manager');

        $this->deleteJson("/api/v1/roles/{$manager->id}", [], $this->authHeader($this->ownerA))->assertOk();

        // And the other workshop still has its own, untouched.
        $this->assertDatabaseHas('roles', [
            'tenant_id' => $this->tenantB->id, 'slug' => 'MANAGER', 'deleted_at' => null,
        ]);
    }

    #[Test]
    public function a_workshop_role_may_never_carry_a_platform_grant_or_the_wildcard(): void
    {
        foreach ([['READ', 'TENANTS'], ['WRITE', 'TENANTS'], ['*', '*']] as [$action, $resource]) {
            $this->postJson('/api/v1/roles', [
                'name' => "Sneaky {$action}{$resource}",
                'permission_ids' => [$this->grant($action, $resource)],
            ], $this->authHeader($this->ownerA))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'RBAC_GRANT_NOT_ALLOWED');
        }
    }

    #[Test]
    public function the_platform_administrator_is_bound_by_the_same_rule_on_a_workshops_role(): void
    {
        $id = $this->createRole($this->ownerA, 'Cashier');

        $this->putJson("/api/v1/roles/{$id}/permissions", [
            'permission_ids' => [$this->grant('READ', 'TENANTS')],
        ], $this->authHeader($this->platformAdmin))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RBAC_GRANT_NOT_ALLOWED');
    }

    #[Test]
    public function nobody_can_hand_out_a_permission_they_do_not_hold(): void
    {
        // A manager who may write roles and read items — and nothing else.
        $manager = $this->createRole($this->ownerA, 'Role Manager', [
            $this->grant('READ', 'ROLES'), $this->grant('WRITE', 'ROLES'), $this->grant('UPDATE', 'ROLES'),
            $this->grant('READ', 'PERMISSIONS'), $this->grant('READ', 'ITEMS'),
        ]);

        $user = User::factory()->forTenant($this->tenantA)->withRole(Role::findOrFail($manager))->create();

        $this->postJson('/api/v1/roles', [
            'name' => 'Escalate',
            'permission_ids' => [$this->grant('WRITE', 'TRANSACTIONS')],
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RBAC_GRANT_NOT_ALLOWED');

        $this->postJson('/api/v1/roles', [
            'name' => 'Viewer',
            'permission_ids' => [$this->grant('READ', 'ITEMS')],
        ], $this->authHeader($user))->assertCreated();
    }

    #[Test]
    public function the_permission_matrix_offers_a_workshop_only_what_it_may_grant(): void
    {
        $matrix = $this->getJson('/api/v1/permissions?grouped=1', $this->authHeader($this->ownerA))
            ->assertOk()->json('data');

        $this->assertArrayNotHasKey('TENANTS', $matrix);
        $this->assertArrayNotHasKey('*', $matrix);
        $this->assertArrayHasKey('ITEMS', $matrix);

        $platform = $this->getJson('/api/v1/permissions?grouped=1', $this->authHeader($this->platformAdmin))
            ->assertOk()->json('data');

        $this->assertArrayHasKey('TENANTS', $platform);
    }

    #[Test]
    public function a_user_cannot_be_given_the_administrator_role_or_another_workshops_role(): void
    {
        $theirs = $this->createRole($this->ownerB, 'Cashier');
        $admin = Role::whereNull('tenant_id')->where('slug', 'ADMIN')->firstOrFail();

        foreach ([$admin->id, $theirs] as $roleId) {
            $this->postJson('/api/v1/users', [
                'name' => 'Clerk One',
                'email' => "clerk{$roleId}@example.com",
                'password' => 'Str0ng!Passw0rd#2026',
                'custom_role_id' => $roleId,
            ], $this->authHeader($this->ownerA))->assertNotFound();
        }
    }

    #[Test]
    public function the_platform_reaches_a_workshops_roles_only_by_acting_inside_it(): void
    {
        $this->createRole($this->ownerA, 'Cashier A');
        $this->createRole($this->ownerB, 'Cashier B');

        $inA = collect($this->getJson("/api/v1/tenants/{$this->tenantA->id}/roles?per_page=100", $this->authHeader($this->platformAdmin))
            ->assertOk()->json('data'));

        $this->assertContains('Cashier A', $inA->pluck('name'));
        $this->assertNotContains('Cashier B', $inA->pluck('name'));
        $inA->each(fn (array $role) => $this->assertSame($this->tenantA->id, $role['tenant_id']));
    }

    #[Test]
    public function a_role_in_use_cannot_be_deleted(): void
    {
        $id = $this->createRole($this->ownerA, 'Cashier');
        User::factory()->forTenant($this->tenantA)->withRole(Role::findOrFail($id))->create();

        $this->deleteJson("/api/v1/roles/{$id}", [], $this->authHeader($this->ownerA))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RBAC_ROLE_IN_USE');
    }
}
