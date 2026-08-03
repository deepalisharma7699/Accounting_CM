<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\TestCase;

/**
 * Registration is workshop sign-up: it provisions a tenant and its owner
 * together. There is no user without a workshop in this product, so the two
 * are inseparable — see AuthService::register().
 */
class RegistrationTest extends TestCase
{
    use InteractsWithAuthModule, RefreshDatabase;

    private const VALID_PASSWORD = 'Str0ng#Passw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        // Sign-up attaches the seeded OWNER role to the first user, so the
        // real catalogue has to exist.
        $this->seedRoleCatalogue();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'workshop_name' => 'Sharma Electricals',
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.com',
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => self::VALID_PASSWORD,
            ...$overrides,
        ];
    }

    public function test_it_provisions_a_workshop_and_its_owner_and_returns_an_access_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_in', 900)
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.user.status', UserStatus::Active->value)
            ->assertJsonPath('data.user.tenant.name', 'Sharma Electricals')
            ->assertJsonPath('data.user.tenant.status', TenantStatus::Active->value)
            ->assertJsonStructure(['data' => ['access_token', 'user' => ['id', 'email', 'tenant_id', 'permissions']]]);

        $tenant = Tenant::where('slug', 'sharma-electricals')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_the_first_user_of_a_workshop_becomes_its_owner(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $user = User::where('email', 'ada@example.com')->with('customRole')->firstOrFail();

        $this->assertSame('OWNER', $user->customRole?->slug);

        // The owner runs their own workshop and nobody else's: no authority
        // over the tenant catalogue.
        $this->assertTrue($user->hasPermissionTo('READ', 'USERS'));
        $this->assertFalse($user->hasPermissionTo('READ', 'TENANTS'));
    }

    public function test_it_stores_a_supplied_gstin_and_derives_the_state_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'gstin' => '27aapfu0939f1zv',
        ]))->assertCreated();

        $this->assertDatabaseHas('tenants', [
            'slug' => 'sharma-electricals',
            'gstin' => '27AAPFU0939F1ZV',
            'state_code' => '27',
        ]);
    }

    public function test_it_rejects_a_malformed_gstin(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['gstin' => 'NOTAGSTIN123456']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['gstin']]]]);
    }

    public function test_it_requires_a_workshop_name(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload(['workshop_name' => '']));

        $response->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['workshop_name']]]]);

        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_it_can_be_disabled_for_sales_led_onboarding(): void
    {
        config()->set('tenancy.allow_public_signup', false);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SIGNUP_DISABLED');

        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_the_password_is_stored_as_a_bcrypt_hash_with_cost_12(): void
    {
        config()->set('hashing.bcrypt.rounds', 12);

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'ada@example.com']))
            ->assertCreated();

        $user = User::where('email', 'ada@example.com')->firstOrFail();

        $this->assertNotSame(self::VALID_PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::VALID_PASSWORD, $user->password));
        $this->assertSame('bcrypt', Hash::info($user->password)['algoName']);
        $this->assertSame(12, Hash::info($user->password)['options']['cost']);
    }

    public function test_the_refresh_token_is_only_returned_as_a_http_only_cookie(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

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
        $this->postJson('/api/v1/auth/register', $this->payload([
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'ada@example.com']));

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // The workshop must not survive a rejected sign-up.
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_it_rejects_a_mismatched_password_confirmation(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'password_confirmation' => 'Something#Else99',
        ]))->assertStatus(422);
    }
}
