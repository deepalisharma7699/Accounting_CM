<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Enums\BalanceSide;
use App\Enums\SystemAccount;
use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Every workshop gets its books the moment it exists.
 */
class ChartOfAccountProvisioningTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private function provisioner(): ChartOfAccountProvisioner
    {
        return app(ChartOfAccountProvisioner::class);
    }

    #[Test]
    public function a_new_workshop_is_provisioned_with_the_full_chart_of_accounts(): void
    {
        $this->seedRoleCatalogue();

        $this->postJson('/api/v1/auth/register', [
            'workshop_name' => 'Sharma Electricals',
            'name' => 'Ravi Sharma',
            'email' => 'ravi@sharma.test',
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
        ])->assertCreated();

        $tenant = Tenant::where('slug', 'sharma-electricals')->firstOrFail();

        $keys = $this->actingForTenant(
            $tenant,
            fn () => ChartOfAccount::orderBy('code')->pluck('system_key')->all()
        );

        $this->assertEqualsCanonicalizing(SystemAccount::cases(), $keys);
    }

    #[Test]
    public function every_seeded_account_matches_its_enum_definition(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingForTenant($tenant, function () {
            foreach (SystemAccount::cases() as $expected) {
                $account = ChartOfAccount::where('system_key', $expected->value)->firstOrFail();

                $this->assertSame($expected->code(), $account->code, "code for {$expected->value}");
                $this->assertSame($expected->accountName(), $account->name, "name for {$expected->value}");
                $this->assertSame($expected->type(), $account->type, "type for {$expected->value}");
                $this->assertTrue($account->is_active);
                $this->assertTrue($account->isSystem());
            }
        });
    }

    #[Test]
    public function every_seeded_account_is_numbered_inside_its_types_band(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingForTenant($tenant, function () {
            foreach (ChartOfAccount::all() as $account) {
                // A misfiled code would sort an expense into the assets in
                // every report that groups by number.
                $this->assertTrue(
                    $account->type->acceptsCode($account->code),
                    "[{$account->code}] {$account->name} is outside the {$account->type->value} band."
                );
            }
        });
    }

    #[Test]
    public function the_seeded_chart_balances_the_accounting_identity(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingForTenant($tenant, function () {
            $debitNormal = ChartOfAccount::all()
                ->filter(fn (ChartOfAccount $a) => $a->normalBalance() === BalanceSide::Debit)
                ->pluck('type')
                ->unique()
                ->values()
                ->all();

            // Assets + Expenses on the debit side; everything else on the
            // credit side. This is the accounting identity, asserted.
            $this->assertEqualsCanonicalizing(
                [AccountType::Asset, AccountType::Expense],
                $debitNormal
            );
        });
    }

    #[Test]
    public function each_workshop_gets_its_own_copy_of_the_chart(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        $alphaCash = $this->actingForTenant($alpha, fn () => ChartOfAccount::where('system_key', SystemAccount::Cash->value)->firstOrFail());
        $betaCash = $this->actingForTenant($beta, fn () => ChartOfAccount::where('system_key', SystemAccount::Cash->value)->firstOrFail());

        // Same system key, same code — different rows. Codes are unique per
        // tenant, not globally.
        $this->assertNotSame($alphaCash->id, $betaCash->id);
        $this->assertSame($alphaCash->code, $betaCash->code);
    }

    /* ---------------------------------------------------------------------
     | Backfill
     |-------------------------------------------------------------------- */

    #[Test]
    public function seeding_again_creates_nothing(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(0, $this->provisioner()->seedFor($tenant));
        $this->assertSame([], $this->provisioner()->missingFor($tenant));
    }

    #[Test]
    public function it_restores_an_account_that_is_missing(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingForTenant(
            $tenant,
            fn () => ChartOfAccount::where('system_key', SystemAccount::Cogs->value)->delete()
        );

        $this->assertSame([SystemAccount::Cogs], $this->provisioner()->missingFor($tenant));
        $this->assertSame(1, $this->provisioner()->seedFor($tenant));

        $restored = $this->actingForTenant(
            $tenant,
            fn () => ChartOfAccount::where('system_key', SystemAccount::Cogs->value)->firstOrFail()
        );

        $this->assertSame('COGS', $restored->name);
    }

    #[Test]
    public function backfilling_does_not_undo_a_workshops_rename(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingForTenant($tenant, function () {
            ChartOfAccount::where('system_key', SystemAccount::Upi->value)
                ->firstOrFail()
                ->update(['name' => 'PhonePe']);
        });

        $this->provisioner()->seedFor($tenant);

        $name = $this->actingForTenant(
            $tenant,
            fn () => ChartOfAccount::where('system_key', SystemAccount::Upi->value)->value('name')
        );

        // The engine resolves on system_key, so a workshop may call an account
        // whatever it likes and a later backfill must leave that alone.
        $this->assertSame('PhonePe', $name);
    }

    #[Test]
    public function the_console_command_backfills_every_workshop(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        foreach ([$alpha, $beta] as $tenant) {
            $this->actingForTenant(
                $tenant,
                fn () => ChartOfAccount::where('system_key', SystemAccount::GstInput->value)->delete()
            );
        }

        $this->artisan('accounts:seed --dry-run')
            ->expectsOutputToContain('Would create 2 account(s)')
            ->assertSuccessful();

        // A dry run must not have written anything.
        $this->assertCount(1, $this->provisioner()->missingFor($alpha));

        $this->artisan('accounts:seed')
            ->expectsOutputToContain('Created 2 account(s)')
            ->assertSuccessful();

        $this->assertSame([], $this->provisioner()->missingFor($alpha));
        $this->assertSame([], $this->provisioner()->missingFor($beta));
    }

    #[Test]
    public function the_console_command_can_target_one_workshop(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        foreach ([$alpha, $beta] as $tenant) {
            $this->actingForTenant(
                $tenant,
                fn () => ChartOfAccount::where('system_key', SystemAccount::Sales->value)->delete()
            );
        }

        $this->artisan("accounts:seed --tenant={$alpha->id}")->assertSuccessful();

        $this->assertSame([], $this->provisioner()->missingFor($alpha));
        $this->assertSame([SystemAccount::Sales], $this->provisioner()->missingFor($beta));
    }
}
