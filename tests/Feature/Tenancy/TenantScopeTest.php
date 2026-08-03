<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\Tenancy\CrossTenantWriteException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Exceptions\Tenancy\NoWorkspaceException;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Fixtures\TenantScopedFixture;
use Tests\TestCase;

/**
 * The BelongsToTenant trait, exercised directly.
 *
 * On PostgreSQL, Row-Level Security would make most of this the database's
 * problem. On MySQL these assertions *are* the isolation guarantee, so they
 * are deliberately paranoid.
 */
class TenantScopeTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $alpha;

    private Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        // `tenant_scoped_fixtures` is created by a test-only migration, not
        // here. Creating it inside the test used to cost about five seconds a
        // test: MySQL commits the open transaction on any DDL, which destroys
        // RefreshDatabase's rollback and leaves every following statement
        // individually committed and fsynced. See
        // AppServiceProvider::loadTestOnlyMigrations().
        $this->alpha = Tenant::factory()->create(['name' => 'Alpha Motors']);
        $this->beta = Tenant::factory()->create(['name' => 'Beta Rewinding']);

        $this->withoutTenantScope(function () {
            TenantScopedFixture::create(['tenant_id' => $this->alpha->id, 'label' => 'alpha-row']);
            TenantScopedFixture::create(['tenant_id' => $this->beta->id, 'label' => 'beta-row']);
        });
    }

    protected function tearDown(): void
    {
        // Everything else is rolled back by RefreshDatabase; only the
        // in-memory context needs resetting.
        $this->tenantContext()->forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_returns_only_the_current_tenants_rows(): void
    {
        $labels = $this->actingForTenant($this->alpha, fn () => TenantScopedFixture::pluck('label')->all());

        $this->assertSame(['alpha-row'], $labels);
    }

    #[Test]
    public function it_hides_another_tenants_row_from_a_direct_lookup_by_id(): void
    {
        $betaRow = $this->withoutTenantScope(
            fn () => TenantScopedFixture::where('label', 'beta-row')->firstOrFail()
        );

        // Knowing the primary key must not be enough. This is the attack that
        // app-layer filtering usually misses.
        $found = $this->actingForTenant($this->alpha, fn () => TenantScopedFixture::find($betaRow->id));

        $this->assertNull($found);
    }

    #[Test]
    public function it_scopes_aggregates_as_well_as_lists(): void
    {
        $count = $this->actingForTenant($this->beta, fn () => TenantScopedFixture::count());

        $this->assertSame(1, $count);
    }

    #[Test]
    public function it_refuses_to_query_when_no_tenant_has_been_established(): void
    {
        // Fail closed. An empty result here would render as a ₹0 report rather
        // than an error, which is the failure mode this design exists to
        // prevent.
        $this->expectException(MissingTenantContextException::class);

        TenantScopedFixture::count();
    }

    #[Test]
    public function a_platform_user_gets_a_no_workspace_error_rather_than_a_server_error(): void
    {
        // Resolved to null — the auth guard ran and this caller simply belongs
        // to no workshop. Their request is well formed and their account is
        // valid, so it is a 403 with an explanation, not a 500. Contrast with
        // the test above, where nobody established tenancy at all.
        $this->actingForTenant(null, function () {
            try {
                TenantScopedFixture::count();
                $this->fail('Expected a NoWorkspaceException.');
            } catch (NoWorkspaceException $e) {
                $this->assertSame(403, $e->status());
                $this->assertSame('NO_WORKSPACE', $e->errorCode());
            }
        });
    }

    #[Test]
    public function it_allows_a_deliberate_unscoped_query(): void
    {
        $count = $this->withoutTenantScope(fn () => TenantScopedFixture::count());

        $this->assertSame(2, $count);
    }

    #[Test]
    public function it_restores_the_previous_context_after_a_nested_run(): void
    {
        $this->actingForTenant($this->alpha, function () {
            $this->actingForTenant($this->beta, fn () => TenantScopedFixture::count());

            $this->assertSame($this->alpha->id, $this->tenantContext()->current());
        });
    }

    #[Test]
    public function it_restores_scoping_even_when_the_callback_throws(): void
    {
        try {
            $this->withoutTenantScope(fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($this->tenantContext()->isUnscoped());
        $this->expectException(MissingTenantContextException::class);
        TenantScopedFixture::count();
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_stamps_the_current_tenant_on_create(): void
    {
        $row = $this->actingForTenant($this->beta, fn () => TenantScopedFixture::create(['label' => 'stamped']));

        $this->assertSame($this->beta->id, $row->tenant_id);
    }

    #[Test]
    public function it_refuses_to_create_a_row_in_another_tenant(): void
    {
        $this->expectException(CrossTenantWriteException::class);

        // A mass-assigned tenant_id must not be able to plant a row in someone
        // else's books.
        $this->actingForTenant($this->alpha, fn () => TenantScopedFixture::create([
            'tenant_id' => $this->beta->id,
            'label' => 'smuggled',
        ]));
    }

    #[Test]
    public function it_refuses_to_create_when_no_tenant_has_been_established(): void
    {
        $this->expectException(MissingTenantContextException::class);

        TenantScopedFixture::create(['label' => 'orphan']);
    }

    #[Test]
    public function it_refuses_to_move_an_existing_row_between_tenants(): void
    {
        $this->expectException(CrossTenantWriteException::class);

        $this->actingForTenant($this->alpha, function () {
            $row = TenantScopedFixture::firstOrFail();

            $row->tenant_id = $this->beta->id;
            $row->save();
        });
    }

    #[Test]
    public function it_scopes_updates_so_another_tenants_row_is_untouched(): void
    {
        $this->actingForTenant($this->alpha, fn () => TenantScopedFixture::query()->update(['label' => 'rewritten']));

        $betaLabel = $this->withoutTenantScope(
            fn () => TenantScopedFixture::where('tenant_id', $this->beta->id)->value('label')
        );

        $this->assertSame('beta-row', $betaLabel);
    }

    #[Test]
    public function it_scopes_deletes_so_another_tenants_row_survives(): void
    {
        $this->actingForTenant($this->alpha, fn () => TenantScopedFixture::query()->delete());

        $remaining = $this->withoutTenantScope(fn () => TenantScopedFixture::pluck('label')->all());

        $this->assertSame(['beta-row'], $remaining);
    }
}
