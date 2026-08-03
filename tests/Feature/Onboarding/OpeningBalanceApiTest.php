<?php

namespace Tests\Feature\Onboarding;

use App\Enums\SystemAccount;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The HTTP surface of the go-live import.
 *
 * Two things are worth watching beyond the happy path. `preview` and `import`
 * are separate verbs rather than one route with a dry-run flag, so committing a
 * workshop's whole financial history can never happen because a boolean was left
 * out. And the whole group needs UPDATE:WORKSPACE as well as WRITE:TRANSACTIONS
 * — declaring what the workshop was worth at go-live is a setup act, not the day
 * job, and a data-entry user holds only the first of the two.
 */
class OpeningBalanceApiTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private const CSV = <<<'CSV'
    kind,name,variant,type,quantity,unit_cost,amount,account
    stock,Ball Bearing,6204,part,10,120.00,,
    receivable,Sharma Motors,,,,,15000.00,
    balance,,,,,,40000.00,Cash in Hand
    CSV;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'LEDGER'], ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'],
            ['READ', 'WORKSPACE'], ['UPDATE', 'WORKSPACE'],
            ['READ', 'ITEMS'], ['WRITE', 'ITEMS'], ['READ', 'PARTIES'], ['WRITE', 'PARTIES'],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Preview
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_preview_resolves_every_row_and_writes_nothing(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/opening-balances/preview', ['csv' => self::CSV])
            ->assertOk();

        $response->assertJsonPath('meta.summary.ready', 3)
            ->assertJsonPath('meta.summary.errors', 0)
            ->assertJsonPath('meta.summary.stock_value', '1200.00')
            ->assertJsonPath('meta.summary.receivable_total', '15000.00')
            ->assertJsonPath('meta.summary.other_total', '40000.00')
            // Assets 56,200 with nothing owed.
            ->assertJsonPath('meta.summary.owners_stake', '56200.00')
            // The records the file is about to invent, counted before anybody
            // agrees to it.
            ->assertJsonPath('meta.summary.items_created', 1)
            ->assertJsonPath('meta.summary.parties_created', 1);

        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => Transaction::query()->count()));
    }

    #[Test]
    public function a_preview_names_the_row_that_cannot_be_resolved(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/opening-balances/preview', [
                'csv' => "kind,amount,account\nbalance,5000.00,Petty Cash Tin",
            ])
            ->assertOk();

        $response->assertJsonPath('data.0.outcome', 'error')
            // Line 2 of the file, because line 1 is the header — the number the
            // user is looking at in their spreadsheet.
            ->assertJsonPath('data.0.line_no', 2)
            ->assertJsonPath('meta.summary.errors', 1);
    }

    #[Test]
    public function typed_rows_are_accepted_as_well_as_a_file(): void
    {
        // The screen offers a grid for a workshop with no spreadsheet, and both
        // paths resolve through the same code.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/opening-balances/preview', [
                'rows' => [
                    ['kind' => 'receivable', 'name' => 'Sharma Motors', 'amount' => '15000.00'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('meta.summary.ready', 1)
            ->assertJsonPath('data.0.resolved', 'Sharma Motors');
    }

    #[Test]
    public function a_request_that_declares_nothing_is_refused(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/opening-balances/preview', [])
            ->assertStatus(422);
    }

    /* ---------------------------------------------------------------------
     | Import
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_import_posts_and_reports_the_position_it_produced(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/opening-balances', ['csv' => self::CSV, 'filename' => 'go-live.csv'])
            ->assertCreated();

        $response->assertJsonPath('data.imported', 3)
            ->assertJsonPath('data.filename', 'go-live.csv')
            ->assertJsonPath('data.items_created', 1)
            ->assertJsonPath('data.parties_created', 1)
            // The one thing somebody wants the instant they commit.
            ->assertJsonPath('meta.position.trial_balance.is_balanced', true)
            ->assertJsonPath('meta.position.owners_stake', '56200.00');

        $this->assertBooksBalance($this->tenant, 'after importing over the API');
        $this->assertSame('56200.00', $this->balanceOf($this->tenant, SystemAccount::OpeningBalanceEquity));
    }

    #[Test]
    public function the_same_file_twice_answers_409_rather_than_posting_again(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/opening-balances', ['csv' => self::CSV])
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/opening-balances', ['csv' => self::CSV])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'OPENING_ALREADY_IMPORTED');
    }

    #[Test]
    public function the_position_reads_back_what_was_declared(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/opening-balances', ['csv' => self::CSV])
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/opening-balances')
            ->assertOk()
            ->assertJsonPath('data.has_opening_balances', true)
            ->assertJsonPath('data.owners_stake', '56200.00')
            ->assertJsonPath('data.trial_balance.is_balanced', true)
            ->assertJsonPath('meta.history.0.imported', 3);
    }

    #[Test]
    public function a_workshop_that_has_declared_nothing_reports_zero_rather_than_an_error(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/opening-balances')
            ->assertOk()
            ->assertJsonPath('data.has_opening_balances', false)
            ->assertJsonPath('data.owners_stake', '0.00')
            ->assertJsonPath('data.trial_balance.is_balanced', true);
    }

    /* ---------------------------------------------------------------------
     | Meta
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_vocabulary_is_published_rather_than_hard_coded_client_side(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/opening-balances/meta')
            ->assertOk();

        $response->assertJsonPath('data.kinds.0.value', 'stock')
            ->assertJsonPath('data.kinds.1.needs_party', true);

        // A service was never on a shelf, so it must not be offered as a type.
        $types = collect($response->json('data.item_types'))->pluck('value')->all();

        $this->assertContains('motor', $types);
        $this->assertNotContains('service', $types);

        // The variant format a new item of each type needs, in the order the
        // segments are read — the inverse of the label the app prints.
        $motor = collect($response->json('data.item_types'))->firstWhere('value', 'motor');

        $this->assertSame('rating / phase / speed', $motor['variant_format']);
    }

    /* ---------------------------------------------------------------------
     | Authority
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_data_entry_user_cannot_declare_the_workshops_net_worth(): void
    {
        // They hold WRITE:TRANSACTIONS and not UPDATE:WORKSPACE. Capturing the
        // day's takings and declaring what the business was worth at go-live are
        // different authorities.
        $clerk = $this->actingForTenant($this->tenant, fn () => User::factory()
            ->forTenant($this->tenant)
            ->withRole($this->roleWith([
                ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'],
                ['READ', 'ITEMS'], ['WRITE', 'ITEMS'],
            ], 'Clerk Role'))
            ->create());

        foreach (['/api/v1/opening-balances/preview', '/api/v1/opening-balances'] as $url) {
            $this->withHeaders($this->authHeader($clerk))
                ->postJson($url, ['csv' => self::CSV])
                ->assertForbidden();
        }
    }

    #[Test]
    public function one_workshops_import_is_invisible_to_another(): void
    {
        [$other, $stranger] = $this->tenantWithUser([
            ['READ', 'LEDGER'], ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'],
            ['UPDATE', 'WORKSPACE'],
        ], 'Other Role');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/opening-balances', ['csv' => self::CSV])
            ->assertCreated();

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/opening-balances')
            ->assertOk()
            ->assertJsonPath('data.has_opening_balances', false)
            ->assertJsonPath('data.owners_stake', '0.00')
            ->assertJsonCount(0, 'meta.history');

        $this->assertBooksBalance($other, 'in a workshop that imported nothing');
    }

    #[Test]
    public function the_same_declarations_in_two_workshops_are_not_a_duplicate(): void
    {
        // The fingerprint is unique per workshop, not globally. Two workshops
        // opening with identical figures is a coincidence.
        [, $stranger] = $this->tenantWithUser([
            ['READ', 'LEDGER'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'WORKSPACE'],
            ['READ', 'ITEMS'], ['WRITE', 'ITEMS'], ['READ', 'PARTIES'], ['WRITE', 'PARTIES'],
        ], 'Twin Role');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/opening-balances', ['csv' => self::CSV])
            ->assertCreated();

        $this->withHeaders($this->authHeader($stranger))
            ->postJson('/api/v1/opening-balances', ['csv' => self::CSV])
            ->assertCreated();
    }
}
