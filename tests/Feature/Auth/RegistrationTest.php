<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use InteractsWithAuthModule, RefreshDatabase;

    private const VALID_PASSWORD = 'Str0ng#Passw0rd!';

    public function test_it_registers_a_user_and_returns_an_access_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => self::VALID_PASSWORD,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_in', 900)
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.user.status', UserStatus::Active->value)
            ->assertJsonStructure(['data' => ['access_token', 'user' => ['id', 'email', 'permissions']]]);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    }

    public function test_the_password_is_stored_as_a_bcrypt_hash_with_cost_12(): void
    {
        config()->set('hashing.bcrypt.rounds', 12);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => self::VALID_PASSWORD,
        ])->assertCreated();

        $user = User::where('email', 'ada@example.com')->firstOrFail();

        $this->assertNotSame(self::VALID_PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::VALID_PASSWORD, $user->password));
        $this->assertSame('bcrypt', Hash::info($user->password)['algoName']);
        $this->assertSame(12, Hash::info($user->password)['options']['cost']);
    }

    public function test_the_refresh_token_is_only_returned_as_a_http_only_cookie(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => self::VALID_PASSWORD,
        ]);

        $cookie = $response->getCookie($this->refreshCookieName(), false);

        $this->assertNotNull($cookie, 'The refresh token cookie was not set.');
        $this->assertNotSame('', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/api/v1/auth', $cookie->getPath());

        // It must never appear in the body, where JavaScript could read it.
        $response->assertJsonMissingPath('data.refresh_token');
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => self::VALID_PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_it_rejects_a_mismatched_password_confirmation(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => 'Something#Else99',
        ])->assertStatus(422);
    }
}
