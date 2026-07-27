<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Services\Rbac\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\TestCase;

class PermissionGuardTest extends TestCase
{
    use InteractsWithAuthModule, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway route exercising the guard exactly as the prompt's
        // checkPermission('WRITE', 'POSTS') example would.
        Route::middleware(['api', 'auth.jwt', 'permission:WRITE,POSTS'])
            ->post('/api/v1/testing/posts', fn () => response()->json(['ok' => true]));

        Route::middleware(['api', 'auth.jwt', 'permission:READ:USERS,WRITE:USERS'])
            ->get('/api/v1/testing/multi', fn () => response()->json(['ok' => true]));
    }

    public function test_it_allows_a_user_holding_the_exact_grant(): void
    {
        $user = $this->userWithGrants([['WRITE', 'POSTS']], 'Author');

        $this->postJson('/api/v1/testing/posts', [], $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_it_denies_a_user_holding_a_different_grant(): void
    {
        $user = $this->userWithGrants([['READ', 'POSTS']], 'Reader');

        $this->postJson('/api/v1/testing/posts', [], $this->authHeader($user))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'AUTH_FORBIDDEN')
            ->assertJsonPath('error.details.required_permissions.0', 'WRITE:POSTS');
    }

    public function test_it_denies_a_user_with_no_role_at_all(): void
    {
        $user = User::factory()->create(['custom_role_id' => null]);

        $this->postJson('/api/v1/testing/posts', [], $this->authHeader($user))
            ->assertStatus(403);
    }

    public function test_the_admin_wildcard_grant_satisfies_any_permission(): void
    {
        $admin = User::factory()->withRole($this->adminRole())->create();

        $this->postJson('/api/v1/testing/posts', [], $this->authHeader($admin))->assertOk();
        $this->getJson('/api/v1/testing/multi', $this->authHeader($admin))->assertOk();
    }

    public function test_a_resource_wildcard_grants_that_action_everywhere(): void
    {
        $user = $this->userWithGrants([['WRITE', '*']], 'Writer');

        $this->postJson('/api/v1/testing/posts', [], $this->authHeader($user))->assertOk();
    }

    public function test_multiple_required_permissions_all_have_to_be_held(): void
    {
        $partial = $this->userWithGrants([['READ', 'USERS']], 'Viewer');

        $this->getJson('/api/v1/testing/multi', $this->authHeader($partial))
            ->assertStatus(403);

        $full = $this->userWithGrants([['READ', 'USERS'], ['WRITE', 'USERS']], 'Manager');

        $this->getJson('/api/v1/testing/multi', $this->authHeader($full))->assertOk();
    }

    public function test_an_unauthenticated_request_is_401_not_403(): void
    {
        $this->postJson('/api/v1/testing/posts')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_MISSING');
    }

    public function test_revoking_a_permission_takes_effect_immediately(): void
    {
        $user = $this->userWithGrants([['WRITE', 'POSTS']], 'Author');

        $this->postJson('/api/v1/testing/posts', [], $this->authHeader($user))->assertOk();

        // Sync through the service so the per-role cache is flushed.
        app(RoleService::class)
            ->syncPermissions((int) $user->custom_role_id, []);

        $this->postJson('/api/v1/testing/posts', [], $this->authHeader($user))
            ->assertStatus(403);
    }
}
