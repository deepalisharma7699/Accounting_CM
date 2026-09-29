<?php

namespace Tests\Feature\Tenancy;

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
 * The platform administrator looks after a workshop's people, roles and
 * settings through /tenants/{tenant}/…, which is served by the same controllers
 * as the workshop's own /users, /roles and /workspace.
 */
class PlatformWorkshopAccessTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private Tenant $other;

    private User $owner;

    private User $otherOwner;

    private User $platformAdmin;

    private const PASSWORD = 'Str0ng!Passw0rd#2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalogue();

        $this->tenant = Tenant::factory()->create();
        $this->other = Tenant::factory()->create();

        // Each workshop's own OWNER — the factory provisions the four defaults,
        // exactly as TenantService does.
        $this->owner = User::factory()->forTenant($this->tenant)
            ->withRole($this->roleOf($this->tenant, 'OWNER'))->create();
        $this->otherOwner = User::factory()->forTenant($this->other)
            ->withRole($this->roleOf($this->other, 'OWNER'))->create();
        $this->platformAdmin = User::factory()->withRole($this->adminRole())->create();
    }

    private function roleOf(Tenant $tenant, string $slug): Role
    {
        return Role::where('tenant_id', $tenant->id)->where('slug', $slug)->firstOrFail();
    }

    private function admin(): array
    {
        return $this->authHeader($this->platformAdmin);
    }

    private function grant(string $action, string $resource): int
    {
        return Permission::where('action', $action)->where('resource', $resource)->firstOrFail()->id;
    }

    /* ---------------------------------------------------------------------
     | Users
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_platform_lists_only_that_workshops_users(): void
    {
        $emails = collect($this->getJson("/api/v1/tenants/{$this->tenant->id}/users", $this->admin())
            ->assertOk()->json('data'))->pluck('email');

        $this->assertContains($this->owner->email, $emails);
        $this->assertNotContains($this->otherOwner->email, $emails);
        $this->assertNotContains($this->platformAdmin->email, $emails);
    }

    #[Test]
    public function a_user_created_by_the_platform_belongs_to_that_workshop(): void
    {
        $role = $this->roleOf($this->tenant, 'DATA_ENTRY');

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/users", [
            'name' => 'Clerk One',
            'email' => 'clerk@example.com',
            'password' => self::PASSWORD,
            'custom_role_id' => $role->id,
        ], $this->admin())
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', $this->tenant->id);

        $this->assertDatabaseHas('users', ['email' => 'clerk@example.com', 'tenant_id' => $this->tenant->id]);
    }

    #[Test]
    public function the_platform_can_change_a_workshop_users_password_role_and_status(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        $role = $this->roleOf($this->tenant, 'DATA_ENTRY');

        $this->patchJson("/api/v1/tenants/{$this->tenant->id}/users/{$user->id}", [
            'name' => 'Renamed',
            'password' => self::PASSWORD,
            'custom_role_id' => $role->id,
            'status' => 'suspended',
        ], $this->admin())
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.status', 'suspended');

        $this->assertTrue(password_verify(self::PASSWORD, $user->fresh()->password));
    }

    #[Test]
    public function a_user_cannot_be_reached_through_the_wrong_workshop(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create();

        $this->patchJson("/api/v1/tenants/{$this->other->id}/users/{$user->id}", ['name' => 'Nope'], $this->admin())
            ->assertNotFound();

        $this->assertNotSame('Nope', $user->fresh()->name);
    }

    #[Test]
    public function the_platform_cannot_give_a_workshop_user_the_administrator_role(): void
    {
        $user = User::factory()->forTenant($this->tenant)->create();
        $admin = Role::whereNull('tenant_id')->where('slug', 'ADMIN')->firstOrFail();

        $this->putJson("/api/v1/tenants/{$this->tenant->id}/users/{$user->id}/role", ['role_id' => $admin->id], $this->admin())
            ->assertNotFound();
    }

    #[Test]
    public function an_unknown_workshop_is_a_404(): void
    {
        $this->getJson('/api/v1/tenants/999999/users', $this->admin())->assertNotFound();
    }

    /* ---------------------------------------------------------------------
     | Roles and settings
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_new_workshop_arrives_with_its_own_four_default_roles(): void
    {
        $roles = collect($this->getJson("/api/v1/tenants/{$this->tenant->id}/roles?per_page=100", $this->admin())
            ->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing(
            ['OWNER', 'MANAGER', 'ACCOUNTANT', 'DATA_ENTRY'],
            $roles->pluck('name')->all(),
        );

        $roles->each(fn (array $role) => $this->assertSame($this->tenant->id, $role['tenant_id']));
    }

    #[Test]
    public function the_platform_sees_the_roles_a_workshop_owner_created(): void
    {
        $id = (int) $this->postJson('/api/v1/roles', ['name' => 'Cashier'], $this->authHeader($this->owner))
            ->assertCreated()->json('data.id');

        $names = collect($this->getJson("/api/v1/tenants/{$this->tenant->id}/roles?per_page=100", $this->admin())
            ->assertOk()->json('data'))->pluck('name');

        $this->assertContains('Cashier', $names);

        // …and never another workshop's view of it.
        $theirs = collect($this->getJson("/api/v1/tenants/{$this->other->id}/roles?per_page=100", $this->admin())
            ->assertOk()->json('data'))->pluck('name');

        $this->assertNotContains('Cashier', $theirs);
        $this->assertNotNull($id);
    }

    #[Test]
    public function a_role_the_platform_creates_for_a_workshop_belongs_to_it(): void
    {
        $id = (int) $this->postJson("/api/v1/tenants/{$this->tenant->id}/roles", [
            'name' => 'Supervisor',
            'permission_ids' => [$this->grant('READ', 'ITEMS')],
        ], $this->admin())
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', $this->tenant->id)
            ->json('data.id');

        // The workshop's own owner has it; the other workshop does not, and the
        // platform's own Roles card does not list it either.
        $this->assertContains('Supervisor', collect(
            $this->getJson('/api/v1/roles?per_page=100', $this->authHeader($this->owner))->assertOk()->json('data')
        )->pluck('name'));

        $this->assertNotContains('Supervisor', collect(
            $this->getJson("/api/v1/tenants/{$this->other->id}/roles?per_page=100", $this->admin())->assertOk()->json('data')
        )->pluck('name'));

        $this->assertNotContains('Supervisor', collect(
            $this->getJson('/api/v1/roles?per_page=100', $this->admin())->assertOk()->json('data')
        )->pluck('name'));

        $this->assertNotNull($id);
    }

    #[Test]
    public function the_platform_can_change_and_delete_a_workshops_role(): void
    {
        $id = (int) $this->postJson("/api/v1/tenants/{$this->tenant->id}/roles", [
            'name' => 'Supervisor',
            'permission_ids' => [$this->grant('READ', 'ITEMS')],
        ], $this->admin())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/tenants/{$this->tenant->id}/roles/{$id}", [
            'name' => 'Shift Supervisor',
        ], $this->admin())->assertOk()->assertJsonPath('data.name', 'Shift Supervisor');

        $this->putJson("/api/v1/tenants/{$this->tenant->id}/roles/{$id}/permissions", [
            'permission_ids' => [$this->grant('READ', 'PARTIES')],
        ], $this->admin())->assertOk();

        $this->deleteJson("/api/v1/tenants/{$this->tenant->id}/roles/{$id}", [], $this->admin())->assertOk();

        $this->assertSoftDeleted('roles', ['id' => $id]);
    }

    #[Test]
    public function a_workshops_role_cannot_be_reached_through_another_workshop(): void
    {
        $id = (int) $this->postJson("/api/v1/tenants/{$this->tenant->id}/roles", [
            'name' => 'Supervisor',
        ], $this->admin())->assertCreated()->json('data.id');

        $this->getJson("/api/v1/tenants/{$this->other->id}/roles/{$id}", $this->admin())->assertNotFound();
        $this->patchJson("/api/v1/tenants/{$this->other->id}/roles/{$id}", ['name' => 'Nope'], $this->admin())->assertNotFound();
        $this->deleteJson("/api/v1/tenants/{$this->other->id}/roles/{$id}", [], $this->admin())->assertNotFound();
    }

    #[Test]
    public function the_platform_reads_and_changes_a_workshops_settings(): void
    {
        $this->getJson("/api/v1/tenants/{$this->tenant->id}/workspace", $this->admin())
            ->assertOk()
            ->assertJsonPath('data.id', $this->tenant->id);

        $this->patchJson("/api/v1/tenants/{$this->tenant->id}/workspace", [
            'payment_due_days' => 45,
            'round_off_invoices' => true,
        ], $this->admin())
            ->assertOk()
            ->assertJsonPath('data.settings.payment_due_days', 45)
            ->assertJsonPath('data.settings.round_off_invoices', true);

        $this->assertSame(45, $this->tenant->fresh()->payment_due_days);
        $this->assertNotSame(45, $this->other->fresh()->payment_due_days);
    }

    #[Test]
    public function a_settings_change_is_written_into_that_workshops_history(): void
    {
        $this->patchJson("/api/v1/tenants/{$this->tenant->id}/workspace", ['payment_due_days' => 45], $this->admin())
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id]);
        $this->assertDatabaseMissing('audit_logs', ['tenant_id' => $this->other->id]);
    }

    /* ---------------------------------------------------------------------
     * Who may use it
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_workshop_owner_cannot_use_the_platform_routes_on_any_workshop(): void
    {
        foreach ([$this->tenant->id, $this->other->id] as $id) {
            $this->getJson("/api/v1/tenants/{$id}/users", $this->authHeader($this->owner))->assertForbidden();
            $this->getJson("/api/v1/tenants/{$id}/roles", $this->authHeader($this->owner))->assertForbidden();
            $this->getJson("/api/v1/tenants/{$id}/workspace", $this->authHeader($this->owner))->assertForbidden();
        }
    }

    #[Test]
    public function a_platform_role_without_the_ordinary_grant_is_still_refused_the_write(): void
    {
        // May administer tenants and read users — but not write them.
        $reader = User::factory()->withRole($this->roleWith([['READ', 'TENANTS'], ['READ', 'USERS']], 'Support'))->create();

        $this->getJson("/api/v1/tenants/{$this->tenant->id}/users", $this->authHeader($reader))->assertOk();

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/users", [
            'name' => 'Nope', 'email' => 'nope@example.com', 'password' => self::PASSWORD,
        ], $this->authHeader($reader))->assertForbidden();
    }

    #[Test]
    public function the_books_are_not_reachable_through_the_platform_routes(): void
    {
        $this->getJson("/api/v1/tenants/{$this->tenant->id}/parties", $this->admin())->assertNotFound();
        $this->getJson("/api/v1/tenants/{$this->tenant->id}/transactions", $this->admin())->assertNotFound();
    }
}
