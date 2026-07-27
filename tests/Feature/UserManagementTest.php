<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use InteractsWithAuthModule, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->withRole($this->adminRole())->create();
    }

    public function test_an_admin_can_list_users_with_filters(): void
    {
        User::factory()->count(3)->create();
        User::factory()->suspended()->create(['name' => 'Suspended Sam']);

        $this->getJson('/api/v1/users?status=suspended', $this->authHeader($this->admin))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Suspended Sam')
            ->assertJsonStructure(['meta' => ['pagination' => ['current_page', 'total']]]);
    }

    public function test_the_password_hash_is_never_exposed(): void
    {
        $response = $this->getJson("/api/v1/users/{$this->admin->id}", $this->authHeader($this->admin))
            ->assertOk();

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertStringNotContainsString('$2y$', $response->getContent());
    }

    public function test_an_admin_can_create_a_user(): void
    {
        $role = Role::factory()->create();

        $this->postJson('/api/v1/users', [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'password' => 'Str0ng#Passw0rd!',
            'custom_role_id' => $role->id,
        ], $this->authHeader($this->admin))
            ->assertCreated()
            ->assertJsonPath('data.email', 'grace@example.com')
            ->assertJsonPath('data.role.id', $role->id);

        $this->assertDatabaseHas('users', ['email' => 'grace@example.com', 'custom_role_id' => $role->id]);
    }

    public function test_assigning_a_new_role_revokes_the_users_sessions(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        app(TokenService::class)->issueRefreshToken($user);
        $this->assertSame(1, RefreshToken::query()->whereNull('revoked_at')->count());

        $this->putJson("/api/v1/users/{$user->id}/role", [
            'role_id' => $role->id,
        ], $this->authHeader($this->admin))
            ->assertOk()
            ->assertJsonPath('data.role.id', $role->id);

        $this->assertSame(0, RefreshToken::query()->whereNull('revoked_at')->count());
        $this->assertSame('privileges_changed', RefreshToken::firstOrFail()->revoked_reason);
    }

    public function test_suspending_a_user_revokes_their_sessions(): void
    {
        $user = User::factory()->create();
        app(TokenService::class)->issueRefreshToken($user);

        $this->putJson("/api/v1/users/{$user->id}/status", [
            'status' => UserStatus::Suspended->value,
        ], $this->authHeader($this->admin))
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertSame(0, RefreshToken::query()->whereNull('revoked_at')->count());
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->putJson("/api/v1/users/{$user->id}/status", [
            'status' => 'exploded',
        ], $this->authHeader($this->admin))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_user_cannot_delete_themselves(): void
    {
        $this->deleteJson("/api/v1/users/{$this->admin->id}", [], $this->authHeader($this->admin))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'USER_SELF_DELETE');
    }

    public function test_an_admin_can_soft_delete_another_user(): void
    {
        $user = User::factory()->create();

        $this->deleteJson("/api/v1/users/{$user->id}", [], $this->authHeader($this->admin))
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_a_user_without_the_grant_cannot_manage_users(): void
    {
        $viewer = $this->userWithGrants([['READ', 'USERS']], 'Viewer');

        $this->getJson('/api/v1/users', $this->authHeader($viewer))->assertOk();

        $this->postJson('/api/v1/users', [
            'name' => 'Nope',
            'email' => 'nope@example.com',
            'password' => 'Str0ng#Passw0rd!',
        ], $this->authHeader($viewer))->assertStatus(403);

        $this->deleteJson("/api/v1/users/{$this->admin->id}", [], $this->authHeader($viewer))
            ->assertStatus(403);
    }

    public function test_a_missing_user_returns_404(): void
    {
        $this->getJson('/api/v1/users/9999', $this->authHeader($this->admin))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_an_unknown_endpoint_returns_the_standard_404_envelope(): void
    {
        $this->getJson('/api/v1/nope')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ENDPOINT_NOT_FOUND');
    }

    public function test_a_wrong_http_verb_returns_405(): void
    {
        $this->putJson('/api/v1/auth/login')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    }
}
