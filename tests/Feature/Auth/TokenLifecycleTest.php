<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\TestCase;

class TokenLifecycleTest extends TestCase
{
    use InteractsWithAuthModule, RefreshDatabase;

    private function signIn(): array
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('Str0ng#Passw0rd!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'Str0ng#Passw0rd!',
        ])->assertOk();

        return [
            'user' => $user,
            'access' => $response->json('data.access_token'),
            'refresh' => $response->getCookie($this->refreshCookieName(), false)->getValue(),
        ];
    }

    public function test_the_access_token_authenticates_the_me_endpoint(): void
    {
        ['user' => $user, 'access' => $access] = $this->signIn();

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$access}"])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_a_missing_or_malformed_token_is_rejected(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_MISSING');

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer not-a-jwt'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_INVALID');
    }

    public function test_a_refresh_token_cannot_be_used_as_an_access_token(): void
    {
        ['refresh' => $refresh] = $this->signIn();

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$refresh}"])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_WRONG_TYPE');
    }

    public function test_an_expired_access_token_is_rejected(): void
    {
        $user = User::factory()->create();

        // The JWT library reads the wall clock, not Carbon's test time, so the
        // token is minted with an already-elapsed lifetime instead.
        config()->set('jwt.ttl.access', -10);
        config()->set('jwt.leeway', 0);

        $token = app(TokenService::class)->issueAccessToken($user);

        config()->set('jwt.ttl.access', 900);

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_EXPIRED');
    }

    public function test_refresh_issues_a_new_pair_and_rotates_the_old_token(): void
    {
        ['refresh' => $refresh] = $this->signIn();

        $original = RefreshToken::firstOrFail();

        $response = $this->withCredentials()
            ->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'expires_in', 'user']]);

        $newCookie = $response->getCookie($this->refreshCookieName(), false)->getValue();

        $this->assertNotSame($refresh, $newCookie, 'The refresh token must rotate.');
        $this->assertTrue($original->fresh()->isRevoked());
        $this->assertSame('rotated', $original->fresh()->revoked_reason);
        $this->assertDatabaseCount('refresh_tokens', 2);
    }

    public function test_reusing_a_rotated_refresh_token_kills_the_whole_family(): void
    {
        ['refresh' => $refresh] = $this->signIn();

        $this->withCredentials()->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk();

        // Past the rotation grace window, so this is a genuine replay rather
        // than a client retrying after a lost response.
        $this->travel(30)->seconds();

        // Replaying the original cookie: treat as a leak.
        $this->withCredentials()->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_REUSED');

        $this->assertSame(
            0,
            RefreshToken::query()->whereNull('revoked_at')->count(),
            'Every token in the family should have been revoked.'
        );
    }

    public function test_a_lost_refresh_response_is_forgiven_inside_the_grace_window(): void
    {
        config()->set('jwt.rotation.grace_seconds', 10);

        ['refresh' => $refresh] = $this->signIn();

        // First refresh succeeds; imagine its response never reached the client
        // (tab closed mid-flight), so the browser still holds the old cookie.
        $this->withCredentials()
            ->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk();

        // Presenting that spent cookie again must NOT destroy the session.
        $this->withCredentials()
            ->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk();

        $this->assertGreaterThan(
            0,
            RefreshToken::query()->whereNull('revoked_at')->count(),
            'The session family must survive a replayed-but-just-rotated token.'
        );
    }

    public function test_the_grace_window_expires(): void
    {
        config()->set('jwt.rotation.grace_seconds', 10);

        ['refresh' => $refresh] = $this->signIn();

        $this->withCredentials()
            ->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk();

        // Past the window the same replay is treated as a leak again.
        $this->travel(30)->seconds();

        $this->withCredentials()
            ->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_REUSED');

        $this->assertSame(0, RefreshToken::query()->whereNull('revoked_at')->count());
    }

    public function test_the_grace_window_never_forgives_a_logged_out_token(): void
    {
        config()->set('jwt.rotation.grace_seconds', 60);

        ['refresh' => $refresh] = $this->signIn();

        $this->withCredentials()
            ->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Revoked by logout, not rotation — the grace window must not apply.
        $this->withCredentials()
            ->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(401);
    }

    public function test_disabling_the_grace_window_restores_strict_reuse_detection(): void
    {
        config()->set('jwt.rotation.grace_seconds', 0);

        ['refresh' => $refresh] = $this->signIn();

        $this->withCredentials()
            ->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk();

        $this->withCredentials()
            ->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_REUSED');
    }

    public function test_refresh_without_a_cookie_returns_401(): void
    {
        $this->postJson('/api/v1/auth/refresh')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_MISSING');
    }

    public function test_logout_revokes_the_refresh_token_and_clears_the_cookie(): void
    {
        ['refresh' => $refresh] = $this->signIn();

        $response = $this->withCredentials()
            ->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame('', $response->getCookie($this->refreshCookieName(), false)->getValue());
        $this->assertTrue(RefreshToken::firstOrFail()->isRevoked());

        // The revoked token can no longer be redeemed.
        $this->withCredentials()->withUnencryptedCookie($this->refreshCookieName(), $refresh)
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(401);
    }

    public function test_logout_all_revokes_every_session(): void
    {
        ['user' => $user, 'access' => $access] = $this->signIn();

        // A second device.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'Str0ng#Passw0rd!',
        ])->assertOk();

        $this->assertSame(2, RefreshToken::query()->whereNull('revoked_at')->count());

        $this->postJson('/api/v1/auth/logout-all', [], ['Authorization' => "Bearer {$access}"])
            ->assertOk()
            ->assertJsonPath('data.sessions_revoked', 2);

        $this->assertSame(0, RefreshToken::query()->whereNull('revoked_at')->count());
        $this->assertSame($user->id, RefreshToken::firstOrFail()->user_id);
    }

    public function test_deactivating_a_user_invalidates_their_access_token_immediately(): void
    {
        ['user' => $user, 'access' => $access] = $this->signIn();

        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$access}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'AUTH_ACCOUNT_INACTIVE');
    }

    public function test_a_token_signed_with_another_key_is_rejected(): void
    {
        $user = User::factory()->create();

        $token = app(TokenService::class)->issueAccessToken($user);

        config()->set('jwt.secret', 'a-completely-different-signing-key-value');

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_INVALID');
    }
}
