<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use InteractsWithAuthModule, RefreshDatabase;

    public function test_it_signs_a_user_in_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('Str0ng#Passw0rd!'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'Str0ng#Passw0rd!',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['access_token', 'expires_in']])
            ->assertCookie($this->refreshCookieName());

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseCount('refresh_tokens', 1);
    }

    public function test_it_returns_401_for_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('Str0ng#Passw0rd!'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'AUTH_INVALID_CREDENTIALS');
    }

    public function test_an_unknown_email_is_indistinguishable_from_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('Str0ng#Passw0rd!'),
        ]);

        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'Str0ng#Passw0rd!',
        ]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ]);

        $unknown->assertStatus(401);
        $wrongPassword->assertStatus(401);
        $this->assertSame(
            $unknown->json('error.message'),
            $wrongPassword->json('error.message'),
            'Login responses must not reveal whether an account exists.'
        );
    }

    public function test_it_locks_the_account_after_the_configured_number_of_failures(): void
    {
        config()->set('rbac.login.max_attempts', 3);

        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('Str0ng#Passw0rd!'),
        ]);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'ada@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        // The attempt that trips the limit reports the lock, not bad credentials.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'AUTH_ACCOUNT_LOCKED')
            ->assertHeader('Retry-After');

        // ...and the correct password is refused while the lock holds.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'Str0ng#Passw0rd!',
        ])->assertStatus(423);

        $this->assertTrue($user->fresh()->isLocked());
    }

    public function test_a_successful_login_clears_the_failure_counter(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('Str0ng#Passw0rd!'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->assertSame(1, $user->fresh()->failed_login_attempts);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'Str0ng#Passw0rd!',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_it_refuses_an_inactive_account(): void
    {
        User::factory()->suspended()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('Str0ng#Passw0rd!'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'Str0ng#Passw0rd!',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'AUTH_ACCOUNT_INACTIVE')
            ->assertJsonPath('error.details.status', UserStatus::Suspended->value);
    }

    public function test_it_rate_limits_repeated_login_attempts(): void
    {
        config()->set('rbac.login.rate_limit.attempts', 3);
        config()->set('rbac.login.max_attempts', 100); // isolate the rate limiter

        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('Str0ng#Passw0rd!'),
        ]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'ada@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    public function test_it_validates_the_payload(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['email', 'password']]]]);
    }
}
