<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class ChartOfAccountApiTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'ACCOUNTS'], ['WRITE', 'ACCOUNTS'], ['UPDATE', 'ACCOUNTS'],
        ]);
    }

    private function systemAccountId(SystemAccount $key): int
    {
        return $this->actingForTenant(
            $this->tenant,
            fn () => ChartOfAccount::where('system_key', $key->value)->value('id')
        );
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_lists_the_whole_chart_ordered_by_code(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/accounts')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->all();

        $this->assertCount(count(SystemAccount::cases()), $codes);
        $this->assertSame(collect($codes)->sort()->values()->all(), $codes);
    }

    #[Test]
    public function it_exposes_the_normal_balance_of_each_account(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/accounts?search=Cash')
            ->assertOk();

        $response->assertJsonPath('data.0.name', 'Cash in Hand')
            ->assertJsonPath('data.0.type', AccountType::Asset->value)
            // Cash is an asset, so a debit increases it.
            ->assertJsonPath('data.0.normal_balance', 'debit')
            ->assertJsonPath('data.0.is_balance_sheet', true)
            ->assertJsonPath('data.0.system_key', SystemAccount::Cash->value)
            ->assertJsonPath('data.0.is_system', true);
    }

    #[Test]
    public function it_filters_by_type(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/accounts?type='.AccountType::Income->value)
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Sales', 'Service Income'], $names);
    }

    #[Test]
    public function it_publishes_the_account_types_with_their_code_bands(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/accounts/types')
            ->assertOk();

        $types = collect($response->json('data'))->keyBy('value');

        $this->assertCount(5, $types);
        $this->assertSame([1000, 1999], $types['asset']['code_range']);
        $this->assertSame('credit', $types['income']['normal_balance']);
        $this->assertFalse($types['expense']['is_balance_sheet']);
    }

    #[Test]
    public function the_chart_is_tenant_scoped(): void
    {
        $otherTenant = Tenant::factory()->create();

        $otherCash = $this->actingForTenant(
            $otherTenant,
            fn () => ChartOfAccount::where('system_key', SystemAccount::Cash->value)->firstOrFail()
        );

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/accounts/{$otherCash->id}")
            ->assertNotFound();
    }

    #[Test]
    public function it_requires_the_accounts_permission(): void
    {
        [, $outsider] = $this->tenantWithUser([['READ', 'USERS']]);

        $this->withHeaders($this->authHeader($outsider))
            ->getJson('/api/v1/accounts')
            ->assertForbidden();
    }

    #[Test]
    public function a_platform_admin_gets_a_clear_403_rather_than_a_server_error(): void
    {
        // A wildcard grant passes the permission guard, so this caller reaches
        // the controller — and then has no books to read. Authority is not
        // membership. Before this was handled the global scope threw a 500 and
        // the response named an internal model class.
        $platformAdmin = User::factory()->withRole($this->adminRole())->create();

        $this->withHeaders($this->authHeader($platformAdmin))
            ->getJson('/api/v1/accounts')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'NO_WORKSPACE');
    }

    #[Test]
    public function a_server_error_never_names_an_internal_class(): void
    {
        config()->set('app.debug', false);

        // Nobody established tenancy — a genuine bug, and a 500. The client
        // gets a stable error code and nothing more: the message naming the
        // model is written for the log, not for a browser.
        $request = Request::create('/api/v1/accounts', 'GET');
        $request->headers->set('Accept', 'application/json');

        $response = app(ApiExceptionRenderer::class)->render(
            MissingTenantContextException::for(ChartOfAccount::class),
            $request,
        );

        $body = $response->getData(true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('TENANT_CONTEXT_MISSING', $body['error']['code']);
        $this->assertStringNotContainsString('ChartOfAccount', $body['error']['message']);
        $this->assertStringNotContainsString('App\\Models', $body['error']['message']);
        $this->assertArrayNotHasKey('details', $body['error']);
    }

    /* ---------------------------------------------------------------------
     | Creating
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_workshop_can_add_an_account_of_its_own(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/accounts', [
                'code' => '5300',
                'name' => 'Workshop Rent',
                'type' => AccountType::Expense->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Workshop Rent')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.system_key', null)
            ->assertJsonPath('data.normal_balance', 'debit');
    }

    #[Test]
    public function it_refuses_a_code_outside_the_types_band(): void
    {
        // 1500 is in the asset block; an expense numbered there would sort
        // into the assets on every report that groups by code.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/accounts', [
                'code' => '1500',
                'name' => 'Misfiled Expense',
                'type' => AccountType::Expense->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACCOUNT_CODE_OUT_OF_BAND')
            ->assertJsonPath('error.details.expected_range', [5000, 5999])
            ->assertJsonPath('error.message', 'An expense account must be numbered between 5000 and 5999.');
    }

    #[Test]
    public function it_refuses_a_duplicate_code(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/accounts', [
                'code' => SystemAccount::Cash->code(),
                'name' => 'Petty Cash',
                'type' => AccountType::Asset->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACCOUNT_CODE_TAKEN');
    }

    #[Test]
    public function it_refuses_a_duplicate_name(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/accounts', [
                'code' => '1040',
                'name' => 'Cash in Hand',
                'type' => AccountType::Asset->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACCOUNT_NAME_TAKEN');
    }

    #[Test]
    public function a_client_cannot_forge_a_system_account(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/accounts', [
                'code' => '4200',
                'name' => 'Fake Sales',
                'type' => AccountType::Income->value,
                // Not in StoreAccountRequest's rules, so it never reaches the
                // service — only the provisioner mints system accounts.
                'system_key' => SystemAccount::Sales->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.system_key', null)
            ->assertJsonPath('data.is_system', false);
    }

    /* ---------------------------------------------------------------------
     | Updating
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_system_account_can_be_renamed(): void
    {
        $id = $this->systemAccountId(SystemAccount::Upi);

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/accounts/{$id}", ['name' => 'PhonePe'])
            ->assertOk()
            ->assertJsonPath('data.name', 'PhonePe')
            // Renaming must not sever the engine's handle on the account.
            ->assertJsonPath('data.system_key', SystemAccount::Upi->value);
    }

    #[Test]
    public function a_system_account_cannot_be_renumbered(): void
    {
        $id = $this->systemAccountId(SystemAccount::Sales);

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/accounts/{$id}", ['code' => '4900'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_SYSTEM_IMMUTABLE');
    }

    #[Test]
    public function a_system_account_cannot_be_archived(): void
    {
        $id = $this->systemAccountId(SystemAccount::Inventory);

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/accounts/{$id}", ['is_active' => false])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_SYSTEM_IMMUTABLE');
    }

    #[Test]
    public function an_accounts_type_can_never_be_changed(): void
    {
        $account = $this->actingForTenant($this->tenant, fn () => ChartOfAccount::factory()
            ->ofType(AccountType::Expense)
            ->create(['code' => '5400', 'name' => 'Electricity']));

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/accounts/{$account->id}", [
                'type' => AccountType::Income->value,
                'name' => 'Electricity Recovered',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Electricity Recovered')
            // `type` is not in UpdateAccountRequest's rules at all:
            // reclassifying would move every past journal entry onto a
            // different financial statement.
            ->assertJsonPath('data.type', AccountType::Expense->value);
    }

    #[Test]
    public function a_custom_account_can_be_archived_and_restored(): void
    {
        $account = $this->actingForTenant($this->tenant, fn () => ChartOfAccount::factory()
            ->ofType(AccountType::Expense)
            ->create(['code' => '5500', 'name' => 'Tea and Snacks']));

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/accounts/{$account->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/accounts?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Tea and Snacks');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/accounts/{$account->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    #[Test]
    public function accounts_cannot_be_deleted(): void
    {
        $id = $this->systemAccountId(SystemAccount::MiscExpense);

        // No DELETE route exists: an account that has been posted to must
        // survive, or its journal entries lose their name.
        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/accounts/{$id}")
            ->assertStatus(405);
    }
}
