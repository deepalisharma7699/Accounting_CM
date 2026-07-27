<?php

namespace Tests\Unit;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Rbac\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\TestCase;

class AuthorizationServiceTest extends TestCase
{
    use InteractsWithAuthModule, RefreshDatabase;

    private AuthorizationService $authorization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorization = app(AuthorizationService::class);
    }

    public function test_an_exact_grant_matches(): void
    {
        $user = $this->userWithGrants([['READ', 'USERS']]);

        $this->assertTrue($this->authorization->userHasPermission($user, 'READ', 'USERS'));
        $this->assertFalse($this->authorization->userHasPermission($user, 'WRITE', 'USERS'));
        $this->assertFalse($this->authorization->userHasPermission($user, 'READ', 'ROLES'));
    }

    public function test_matching_is_case_insensitive(): void
    {
        $user = $this->userWithGrants([['READ', 'USERS']]);

        $this->assertTrue($this->authorization->userHasPermission($user, 'read', 'users'));
    }

    public function test_the_full_wildcard_grants_everything(): void
    {
        $user = User::factory()->withRole($this->adminRole())->create();

        $this->assertTrue($this->authorization->userHasPermission($user, 'DELETE', 'ANYTHING'));
        $this->assertTrue($this->authorization->userHasPermission($user, 'SOMETHING', 'ELSE'));
    }

    public function test_an_action_wildcard_is_scoped_to_its_resource(): void
    {
        $user = $this->userWithGrants([['*', 'INVOICES']]);

        $this->assertTrue($this->authorization->userHasPermission($user, 'DELETE', 'INVOICES'));
        $this->assertFalse($this->authorization->userHasPermission($user, 'DELETE', 'USERS'));
    }

    public function test_a_user_without_a_role_has_no_permissions(): void
    {
        $user = User::factory()->create(['custom_role_id' => null]);

        $this->assertFalse($this->authorization->userHasPermission($user, 'READ', 'USERS'));
        $this->assertSame([], $this->authorization->grantsForUser($user));
    }

    public function test_an_inactive_user_has_no_permissions_even_with_a_role(): void
    {
        $user = User::factory()
            ->withRole($this->adminRole())
            ->withStatus(UserStatus::Suspended)
            ->create();

        $this->assertFalse($this->authorization->userHasPermission($user, 'READ', 'USERS'));
    }

    public function test_all_permissions_must_be_held(): void
    {
        $user = $this->userWithGrants([['READ', 'USERS']]);

        $this->assertTrue($this->authorization->userHasAllPermissions($user, [['READ', 'USERS']]));
        $this->assertFalse($this->authorization->userHasAllPermissions($user, [
            ['READ', 'USERS'],
            ['WRITE', 'USERS'],
        ]));
    }

    public function test_flushing_the_cache_picks_up_a_changed_grant(): void
    {
        $user = $this->userWithGrants([['READ', 'USERS']]);
        $roleId = (int) $user->custom_role_id;

        $this->assertTrue($this->authorization->userHasPermission($user, 'READ', 'USERS'));

        $user->customRole->permissions()->detach();
        $this->authorization->flushRoleCache($roleId);

        $this->assertFalse($this->authorization->userHasPermission($user, 'READ', 'USERS'));
    }
}
