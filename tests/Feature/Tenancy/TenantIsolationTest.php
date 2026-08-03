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
 * Isolation of the `users` table, end to end through the HTTP API.
 *
 * Users are the one table not protected by a global scope — the auth path has
 * to read them before a tenant exists — so the boundary lives in
 * EloquentUserRepository::scoped() and is proven here rather than by
 * TenantScopeTest.
 */
class TenantIsolationTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $alpha;

    private User $alphaOwner;

    private Tenant $beta;

    private User $betaStaff;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->alpha, $this->alphaOwner] = $this->tenantWithUser([
            ['READ', 'USERS'], ['WRITE', 'USERS'], ['UPDATE', 'USERS'], ['DELETE', 'USERS'],
        ], 'Alpha Owner');

        $this->beta = Tenant::factory()->create();
        $this->betaStaff = User::factory()->forTenant($this->beta)->create(['name' => 'Beta Staff']);
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    #[Test]
    public function listing_users_returns_only_the_callers_tenant(): void
    {
        $response = $this->withHeaders($this->authHeader($this->alphaOwner))
            ->getJson('/api/v1/users')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($this->alphaOwner->id, $ids);
        $this->assertNotContains($this->betaStaff->id, $ids);
    }

    #[Test]
    public function fetching_a_user_from_another_tenant_is_a_404_not_a_403(): void
    {
        // 404, deliberately: a 403 would confirm that the id exists, which is
        // itself a leak — an attacker could enumerate other workshops' users.
        $this->withHeaders($this->authHeader($this->alphaOwner))
            ->getJson("/api/v1/users/{$this->betaStaff->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    #[Test]
    public function updating_a_user_from_another_tenant_is_refused(): void
    {
        $this->withHeaders($this->authHeader($this->alphaOwner))
            ->patchJson("/api/v1/users/{$this->betaStaff->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Beta Staff', $this->betaStaff->fresh()->name);
    }

    #[Test]
    public function deleting_a_user_from_another_tenant_is_refused(): void
    {
        $this->withHeaders($this->authHeader($this->alphaOwner))
            ->deleteJson("/api/v1/users/{$this->betaStaff->id}")
            ->assertNotFound();

        $this->assertNotSoftDeleted($this->betaStaff);
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_created_user_lands_in_the_callers_tenant(): void
    {
        $this->withHeaders($this->authHeader($this->alphaOwner))
            ->postJson('/api/v1/users', [
                'name' => 'New Fitter',
                'email' => 'fitter@alpha.test',
                'password' => 'Str0ng!Passw0rd#2026',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', $this->alpha->id);
    }

    #[Test]
    public function a_client_cannot_choose_which_tenant_a_new_user_joins(): void
    {
        // tenant_id is not in StoreUserRequest's rules, so it never reaches the
        // service; the repository stamps the caller's tenant regardless.
        $this->withHeaders($this->authHeader($this->alphaOwner))
            ->postJson('/api/v1/users', [
                'name' => 'Planted',
                'email' => 'planted@alpha.test',
                'password' => 'Str0ng!Passw0rd#2026',
                'tenant_id' => $this->beta->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', $this->alpha->id);
    }

    /* ---------------------------------------------------------------------
     | Platform users
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_platform_admin_does_not_see_tenant_users_in_the_user_list(): void
    {
        $platformAdmin = $this->userWithGrants([['*', '*']], 'Platform');

        $response = $this->withHeaders($this->authHeader($platformAdmin))
            ->getJson('/api/v1/users')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        // A wildcard grant is authority, not omniscience: the tenant boundary
        // is orthogonal to permissions. Platform staff manage workshops
        // through /tenants, not by browsing their people.
        $this->assertContains($platformAdmin->id, $ids);
        $this->assertNotContains($this->alphaOwner->id, $ids);
        $this->assertNotContains($this->betaStaff->id, $ids);
    }

    /* ---------------------------------------------------------------------
     | Tenant status
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_suspended_tenant_locks_out_its_users(): void
    {
        $this->alpha->update(['status' => TenantStatus::Suspended]);

        $this->withHeaders($this->authHeader($this->alphaOwner))
            ->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'TENANT_INACTIVE');
    }

    #[Test]
    public function a_suspended_tenant_cannot_sign_in(): void
    {
        $this->alpha->update(['status' => TenantStatus::Suspended]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $this->alphaOwner->email,
            'password' => 'password',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'TENANT_INACTIVE');
    }

    #[Test]
    public function suspending_a_tenant_does_not_affect_another(): void
    {
        $this->alpha->update(['status' => TenantStatus::Suspended]);

        $this->withHeaders($this->authHeader($this->betaStaff))
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }
}
