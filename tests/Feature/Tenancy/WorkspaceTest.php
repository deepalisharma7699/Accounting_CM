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
 * A workshop owner managing their own workshop.
 *
 * The security property under test throughout: /workspace has no id in its
 * URL, so there is nothing for a caller to tamper with. It always resolves to
 * whatever tenant the auth guard established, and never to another.
 */
class WorkspaceTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'WORKSPACE'], ['UPDATE', 'WORKSPACE'],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_owner_can_read_their_own_workshop(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/workspace')
            ->assertOk()
            ->assertJsonPath('data.id', $this->tenant->id)
            ->assertJsonPath('data.name', $this->tenant->name)
            ->assertJsonPath('data.settings.currency', 'INR')
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'slug', 'gstin', 'address', 'state_code', 'status',
                    'settings' => ['financial_year_start_month', 'timezone', 'currency', 'books_start_date'],
                    'current_financial_year' => ['start', 'end'],
                ],
            ]);
    }

    #[Test]
    public function it_always_resolves_the_callers_own_workshop(): void
    {
        $other = Tenant::factory()->create(['name' => 'Someone Else Motors']);
        $otherOwner = User::factory()->forTenant($other)
            ->withRole($this->roleWith([['READ', 'WORKSPACE']], 'Other Owner'))
            ->create();

        // Same URL, two callers, two different workshops. There is no id to
        // swap, so this is structural rather than a check that could be missed.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/workspace')
            ->assertOk()
            ->assertJsonPath('data.id', $this->tenant->id);

        $this->withHeaders($this->authHeader($otherOwner))
            ->getJson('/api/v1/workspace')
            ->assertOk()
            ->assertJsonPath('data.id', $other->id);
    }

    #[Test]
    public function a_data_entry_user_cannot_reach_the_workspace(): void
    {
        [, $staff] = $this->tenantWithUser([['READ', 'ACCOUNTS']], 'Floor Staff');

        $this->withHeaders($this->authHeader($staff))
            ->getJson('/api/v1/workspace')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_FORBIDDEN');
    }

    #[Test]
    public function a_platform_admin_gets_a_clear_error_rather_than_an_empty_workshop(): void
    {
        // Wildcard grant, so permissions are not the obstacle — having no
        // tenant is. The message points them at /tenants instead of leaving
        // them with an empty object.
        $platformAdmin = User::factory()->withRole($this->adminRole())->create();

        $this->withHeaders($this->authHeader($platformAdmin))
            ->getJson('/api/v1/workspace')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'NO_WORKSPACE');
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->getJson('/api/v1/workspace')->assertUnauthorized();
    }

    /* ---------------------------------------------------------------------
     | Editing
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_owner_can_update_their_workshop_details(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', [
                'name' => 'Sharma Electricals & Rewinding',
                'address' => '12 MIDC Road, Pune',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sharma Electricals & Rewinding')
            ->assertJsonPath('data.address', '12 MIDC Road, Pune');
    }

    #[Test]
    public function setting_a_gstin_after_signup_re_derives_the_state_code(): void
    {
        // Signed up without a GSTIN, so the state code is whatever the default
        // was. Supplying the GSTIN later must correct it — otherwise every
        // bill picks the wrong side of the intra/inter-state GST split.
        $this->assertSame('27', $this->tenant->state_code);

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', ['gstin' => '09aapfu0939f1zv'])
            ->assertOk()
            ->assertJsonPath('data.gstin', '09AAPFU0939F1ZV')
            ->assertJsonPath('data.state_code', '09');

        $this->assertSame('09', $this->tenant->fresh()->state_code);
    }

    #[Test]
    public function resubmitting_an_unchanged_gstin_is_not_a_duplicate(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', ['gstin' => '27AAPFU0939F1ZV'])
            ->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', ['gstin' => '27AAPFU0939F1ZV', 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    #[Test]
    public function it_rejects_a_gstin_another_workshop_already_uses(): void
    {
        Tenant::factory()->withGstin('27AAPFU0939F1ZV')->create();

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', ['gstin' => '27AAPFU0939F1ZV'])
            ->assertStatus(422);
    }

    #[Test]
    public function an_owner_cannot_change_their_own_status(): void
    {
        $this->tenant->update(['status' => TenantStatus::Active]);

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', [
                'name' => 'Still Editable',
                'status' => TenantStatus::Cancelled->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Still Editable')
            // Suspension is a platform decision. The field is absent from the
            // request rules *and* stripped in the service.
            ->assertJsonPath('data.status', TenantStatus::Active->value);
    }

    #[Test]
    public function an_owner_cannot_change_their_slug_or_currency(): void
    {
        $originalSlug = $this->tenant->slug;

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', [
                'slug' => 'hijacked-slug',
                'currency' => 'USD',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', $originalSlug)
            ->assertJsonPath('data.settings.currency', 'INR');
    }

    #[Test]
    public function a_suspended_workshop_cannot_edit_itself(): void
    {
        $this->tenant->update(['status' => TenantStatus::Suspended]);

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', ['name' => 'Nice Try'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'TENANT_INACTIVE');
    }

    /* ---------------------------------------------------------------------
     | Settings
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_new_workshop_has_no_null_settings(): void
    {
        $this->seedRoleCatalogue();

        $this->postJson('/api/v1/auth/register', [
            'workshop_name' => 'Fresh Motors',
            'name' => 'New Owner',
            'email' => 'new@fresh.test',
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
        ])->assertCreated();

        $fresh = Tenant::where('slug', 'fresh-motors')->firstOrFail();

        // Settings are stamped at provisioning, never resolved from config at
        // read time — a report must not change retrospectively because someone
        // edited a config file.
        $this->assertSame(4, $fresh->financial_year_start_month);
        $this->assertSame('Asia/Kolkata', $fresh->timezone);
        $this->assertSame('INR', $fresh->currency);
        $this->assertNull($fresh->books_start_date);
    }

    #[Test]
    public function an_owner_can_set_the_financial_year_and_go_live_date(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', [
                'financial_year_start_month' => 1,
                'books_start_date' => '2026-04-01',
                'timezone' => 'Asia/Kolkata',
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.financial_year_start_month', 1)
            ->assertJsonPath('data.settings.books_start_date', '2026-04-01')
            // A January start means the financial year is the calendar year.
            ->assertJsonPath('data.current_financial_year.start', '2026-01-01')
            ->assertJsonPath('data.current_financial_year.end', '2026-12-31');
    }

    #[Test]
    public function it_rejects_an_impossible_financial_year_start(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', ['financial_year_start_month' => 13])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['financial_year_start_month']]]]);
    }

    #[Test]
    public function it_rejects_an_unrecognised_timezone(): void
    {
        // Caught here rather than at report time, where an invalid identifier
        // would blow up date formatting for every page at once.
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', ['timezone' => 'Mars/Olympus_Mons'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['timezone']]]]);
    }

    #[Test]
    public function a_patch_never_blanks_a_field_it_did_not_mention(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', ['gstin' => '27AAPFU0939F1ZV', 'address' => 'Pune'])
            ->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson('/api/v1/workspace', ['name' => 'Only The Name'])
            ->assertOk()
            ->assertJsonPath('data.gstin', '27AAPFU0939F1ZV')
            ->assertJsonPath('data.address', 'Pune');
    }
}
