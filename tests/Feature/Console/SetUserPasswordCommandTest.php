<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetUserPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'Str0ng#Passw0rd!';

    public function test_it_sets_a_policy_compliant_password(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->artisan('user:password', [
            'email' => 'ada@example.com',
            '--password' => self::STRONG,
        ])->assertSuccessful();

        $this->assertTrue(Hash::check(self::STRONG, $user->fresh()->password));
    }

    public function test_it_refuses_a_weak_password_without_force(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $original = $user->password;

        $this->artisan('user:password', [
            'email' => 'ada@example.com',
            '--password' => '12345',
        ])->assertFailed();

        $this->assertSame($original, $user->fresh()->password, 'The password must be left untouched.');
    }

    public function test_force_accepts_a_weak_password(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->artisan('user:password', [
            'email' => 'ada@example.com',
            '--password' => '12345',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertTrue(Hash::check('12345', $user->fresh()->password));
    }

    public function test_it_renames_a_user_without_touching_the_password(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Old Name']);
        $original = $user->password;

        $this->artisan('user:password', [
            'email' => 'ada@example.com',
            '--name' => 'Harshita Sharma',
        ])->assertSuccessful();

        $user->refresh();

        $this->assertSame('Harshita Sharma', $user->name);
        $this->assertSame($original, $user->password);
    }

    public function test_a_rename_does_not_revoke_sessions_but_a_password_change_does(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $tokens = app(TokenService::class);

        $tokens->issueRefreshToken($user);
        $this->assertSame(1, $user->refreshTokens()->whereNull('revoked_at')->count());

        $this->artisan('user:password', [
            'email' => 'ada@example.com',
            '--name' => 'Renamed Only',
        ])->assertSuccessful();

        $this->assertSame(
            1,
            $user->refreshTokens()->whereNull('revoked_at')->count(),
            'Renaming must not sign the user out.'
        );

        $this->artisan('user:password', [
            'email' => 'ada@example.com',
            '--password' => self::STRONG,
        ])->assertSuccessful();

        $this->assertSame(
            0,
            $user->refreshTokens()->whereNull('revoked_at')->count(),
            'A password change must end existing sessions.'
        );
    }

    public function test_keep_sessions_preserves_refresh_tokens(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        app(TokenService::class)->issueRefreshToken($user);

        $this->artisan('user:password', [
            'email' => 'ada@example.com',
            '--password' => self::STRONG,
            '--keep-sessions' => true,
        ])->assertSuccessful();

        $this->assertSame(1, $user->refreshTokens()->whereNull('revoked_at')->count());
    }

    public function test_unlock_clears_the_lockout(): void
    {
        $user = User::factory()->locked()->create(['email' => 'ada@example.com']);

        $this->assertTrue($user->isLocked());

        $this->artisan('user:password', [
            'email' => 'ada@example.com',
            '--name' => 'Unlocked User',
            '--unlock' => true,
        ])->assertSuccessful();

        $user->refresh();

        $this->assertFalse($user->isLocked());
        $this->assertSame(0, $user->failed_login_attempts);
    }

    public function test_it_rejects_an_invalid_name(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Old Name']);

        $this->artisan('user:password', [
            'email' => 'ada@example.com',
            '--name' => 'H',
        ])->assertFailed();

        $this->assertSame('Old Name', $user->fresh()->name);
    }

    public function test_it_fails_for_an_unknown_email(): void
    {
        $this->artisan('user:password', [
            'email' => 'nobody@example.com',
            '--name' => 'Ghost',
        ])->assertFailed();
    }
}
