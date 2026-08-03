<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The platform super-admin surface: provisioning, suspending and deleting
 * workshops.
 */
class TenantManagementTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Provisioning with an owner attaches the seeded OWNER role.
        $this->seedRoleCatalogue();

        $this->platformAdmin = User::factory()->withRole($this->adminRole())->create();
    }

    private function password(): string
    {
        return 'Str0ng!Passw0rd#2026';
    }

    /* ---------------------------------------------------------------------
     | Authorization
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_workshop_owner_cannot_reach_the_tenant_administration_api(): void
    {
        [, $owner] = $this->tenantWithUser([['READ', 'USERS'], ['WRITE', 'USERS']]);

        $this->withHeaders($this->authHeader($owner))
            ->getJson('/api/v1/tenants')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_FORBIDDEN');
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->getJson('/api/v1/tenants')->assertUnauthorized();
    }

    /* ---------------------------------------------------------------------
     | Provisioning
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_provisions_a_workshop(): void
    {
        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->postJson('/api/v1/tenants', ['name' => 'Sharma Electricals'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sharma Electricals')
            ->assertJsonPath('data.slug', 'sharma-electricals')
            ->assertJsonPath('data.status', TenantStatus::Active->value);
    }

    #[Test]
    public function it_provisions_a_workshop_with_its_owner(): void
    {
        $response = $this->withHeaders($this->authHeader($this->platformAdmin))
            ->postJson('/api/v1/tenants', [
                'name' => 'Verma Rewinding',
                'owner' => [
                    'name' => 'Anil Verma',
                    'email' => 'anil@verma.test',
                    'password' => $this->password(),
                ],
            ])
            ->assertCreated();

        $tenantId = $response->json('data.tenant.id');

        $this->assertDatabaseHas('users', [
            'email' => 'anil@verma.test',
            'tenant_id' => $tenantId,
        ]);

        // The owner must be able to sign in immediately — a workshop nobody
        // can reach is not provisioned, it is stranded.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'anil@verma.test',
            'password' => $this->password(),
        ])->assertOk()->assertJsonPath('data.user.tenant_id', $tenantId);
    }

    #[Test]
    public function it_gives_colliding_workshop_names_distinct_slugs(): void
    {
        foreach (['sharma-electricals', 'sharma-electricals-2'] as $expected) {
            $this->withHeaders($this->authHeader($this->platformAdmin))
                ->postJson('/api/v1/tenants', ['name' => 'Sharma Electricals'])
                ->assertCreated()
                ->assertJsonPath('data.slug', $expected);
        }
    }

    #[Test]
    public function it_derives_the_state_code_from_the_gstin(): void
    {
        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->postJson('/api/v1/tenants', [
                'name' => 'Pune Motors',
                'gstin' => '27AAPFU0939F1ZV',
                // Contradicts the GSTIN; the GSTIN wins, because the two
                // cannot legally disagree.
                'state_code' => '09',
            ])
            ->assertCreated()
            ->assertJsonPath('data.state_code', '27');
    }

    #[Test]
    public function it_rejects_a_malformed_gstin(): void
    {
        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->postJson('/api/v1/tenants', ['name' => 'Bad GST', 'gstin' => 'NOTAGSTIN123456'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function it_rejects_a_duplicate_gstin(): void
    {
        Tenant::factory()->withGstin('27AAPFU0939F1ZV')->create();

        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->postJson('/api/v1/tenants', ['name' => 'Copycat', 'gstin' => '27AAPFU0939F1ZV'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_half_supplied_owner_block_is_rejected(): void
    {
        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->postJson('/api/v1/tenants', [
                'name' => 'Half Owner',
                'owner' => ['email' => 'someone@example.test'],
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('tenants', ['name' => 'Half Owner']);
    }

    #[Test]
    public function a_failed_owner_creation_rolls_the_workshop_back(): void
    {
        $existing = User::factory()->create(['email' => 'taken@example.test']);

        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->postJson('/api/v1/tenants', [
                'name' => 'Doomed Workshop',
                'owner' => [
                    'name' => 'Someone',
                    'email' => $existing->email,
                    'password' => $this->password(),
                ],
            ])
            ->assertStatus(422);

        // A tenant with no owner would be unreachable and invisible: both
        // halves commit together or neither does.
        $this->assertDatabaseMissing('tenants', ['name' => 'Doomed Workshop']);
    }

    /* ---------------------------------------------------------------------
     | Administration
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_lists_and_filters_workshops(): void
    {
        Tenant::factory()->create(['name' => 'Findable Motors']);
        Tenant::factory()->suspended()->create(['name' => 'Dormant Motors']);

        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->getJson('/api/v1/tenants?search=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Findable Motors');

        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->getJson('/api/v1/tenants?status='.TenantStatus::Suspended->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Dormant Motors');
    }

    #[Test]
    public function it_reports_the_user_count_on_a_single_workshop(): void
    {
        $tenant = Tenant::factory()->create();
        User::factory()->count(3)->forTenant($tenant)->create();

        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->getJson("/api/v1/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('data.user_count', 3);
    }

    #[Test]
    public function renaming_a_workshop_leaves_its_slug_alone(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);

        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->patchJson("/api/v1/tenants/{$tenant->id}", ['name' => 'Brand New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Brand New Name')
            // The slug may already be in URLs, logs and support tickets.
            ->assertJsonPath('data.slug', 'old-name');
    }

    #[Test]
    public function suspending_a_workshop_ends_every_session_inside_it(): void
    {
        $tenant = Tenant::factory()->create();
        $staff = User::factory()->forTenant($tenant)->create();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertOk();

        $refreshCookie = $login->getCookie($this->refreshCookieName(), false)->getValue();

        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->putJson("/api/v1/tenants/{$tenant->id}/status", ['status' => TenantStatus::Suspended->value])
            ->assertOk();

        // The access token is refused by the guard, and the refresh token can
        // no longer mint a replacement — otherwise suspension would take up to
        // seven days to bite.
        $this->withCredentials()
            ->withCookie($this->refreshCookieName(), $refreshCookie)
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(401);
    }

    #[Test]
    public function reactivating_a_workshop_restores_access(): void
    {
        $tenant = Tenant::factory()->suspended()->create();
        $staff = User::factory()->forTenant($tenant)->create();

        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->putJson("/api/v1/tenants/{$tenant->id}/status", ['status' => TenantStatus::Active->value])
            ->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertOk();
    }

    /* ---------------------------------------------------------------------
     | Deletion
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_refuses_to_delete_a_workshop_that_still_has_users(): void
    {
        $tenant = Tenant::factory()->create();
        User::factory()->forTenant($tenant)->create();

        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->deleteJson("/api/v1/tenants/{$tenant->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TENANT_IN_USE');

        $this->assertNotSoftDeleted($tenant);
    }

    #[Test]
    public function it_deletes_an_empty_workshop(): void
    {
        $tenant = Tenant::factory()->create();

        $this->withHeaders($this->authHeader($this->platformAdmin))
            ->deleteJson("/api/v1/tenants/{$tenant->id}")
            ->assertOk();

        $this->assertSoftDeleted($tenant);
    }
}
