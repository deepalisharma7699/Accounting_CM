<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Models\ChartOfAccount;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class PartyApiTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithLedger, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'PARTIES'], ['WRITE', 'PARTIES'], ['UPDATE', 'PARTIES'], ['DELETE', 'PARTIES'],
            ['READ', 'LEDGER'], ['WRITE', 'TRANSACTIONS'], ['READ', 'TRANSACTIONS'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function party(array $overrides = []): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create($overrides));
    }

    private function accountId(SystemAccount $key): int
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
    public function it_lists_parties_with_their_roles(): void
    {
        $this->party(['name' => 'Alpha Motors', 'roles' => ['customer', 'vendor']]);

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties')
            ->assertOk();

        $response->assertJsonPath('data.0.name', 'Alpha Motors')
            ->assertJsonPath('data.0.roles', ['customer', 'vendor'])
            ->assertJsonPath('data.0.role_labels', ['Customer', 'Vendor'])
            ->assertJsonPath('data.0.is_customer', true)
            ->assertJsonPath('data.0.is_vendor', true);
    }

    #[Test]
    public function outstanding_is_null_unless_it_was_asked_for(): void
    {
        // Null and zero mean different things: "nobody looked" against "nothing
        // owed". A picker that reported the first as the second would tell a
        // reader an account is settled when it may not be.
        $party = $this->party();

        $this->postSimpleJournal(
            $this->tenant, SystemAccount::Receivables, SystemAccount::Sales, '1500.00', party: $party
        );

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties')
            ->assertOk()
            ->assertJsonPath('data.0.outstanding', null);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties?with_position=1')
            ->assertOk()
            ->assertJsonPath('data.0.outstanding.receivable', '1500.00')
            ->assertJsonPath('data.0.outstanding.payable', '0.00')
            ->assertJsonPath('data.0.outstanding.net', '1500.00');
    }

    #[Test]
    public function it_reports_what_has_gone_through_a_relationship_beside_what_is_left_of_it(): void
    {
        // The customer and vendor screens show both: the outstanding figure is
        // what to chase, the lifetime figure is how much the relationship is
        // worth. They are the netted and gross readings of the same control
        // account rows, which is why they can never tell different stories —
        // billed minus received *is* the receivable.
        $party = $this->party(['roles' => ['customer']]);

        $this->postSimpleJournal(
            $this->tenant, SystemAccount::Receivables, SystemAccount::Sales, '1500.00', party: $party
        );

        $this->postSimpleJournal(
            $this->tenant, SystemAccount::Cash, SystemAccount::Receivables, '500.00', party: $party
        );

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties?with_position=1')
            ->assertOk()
            ->assertJsonPath('data.0.outstanding.receivable', '1000.00')
            ->assertJsonPath('data.0.lifetime.billed', '1500.00')
            ->assertJsonPath('data.0.lifetime.received', '500.00')
            // Nothing has been bought from them, and that is stated as a zero
            // rather than left out — the screen shows the figure either way.
            ->assertJsonPath('data.0.lifetime.purchased', '0.00')
            ->assertJsonPath('data.0.lifetime.paid', '0.00');

        // Same opt-in as the position, and for the same reason: a picker has no
        // use for either and should not pay for them.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties')
            ->assertOk()
            ->assertJsonPath('data.0.lifetime', null);
    }

    #[Test]
    public function it_reports_when_a_party_was_last_dealt_with_when_asked(): void
    {
        $party = $this->party();

        $this->postSimpleJournal(
            $this->tenant, SystemAccount::Receivables, SystemAccount::Sales, '900.00',
            date: '2026-03-14', party: $party,
        );

        $this->postSimpleJournal(
            $this->tenant, SystemAccount::Receivables, SystemAccount::Sales, '400.00',
            date: '2026-05-02', party: $party,
        );

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties?with_activity=1')
            ->assertOk()
            // The latest, not the first and not the most recently entered: a
            // bill captured on Monday may be dated Friday, and the relationship
            // is dated by when it happened.
            ->assertJsonPath('data.0.activity.last_transaction_at', '2026-05-02')
            ->assertJsonPath('data.0.activity.transaction_count', 2)
            // Neither was a sale voucher — both were journals — so the columns
            // that report a *kind* of dealing stay empty rather than borrowing
            // the general answer.
            ->assertJsonPath('data.0.activity.last_sale_at', null)
            ->assertJsonPath('data.0.activity.last_purchase_at', null);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties')
            ->assertOk()
            ->assertJsonPath('data.0.activity', null);
    }

    #[Test]
    public function a_party_nobody_has_traded_with_reports_zeroes_and_nulls_not_absence(): void
    {
        // "Never dealt with" is an answer. A client should not have to tell it
        // apart from "not fetched", which is what the missing key would mean.
        $this->party();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties?with_position=1&with_activity=1')
            ->assertOk()
            ->assertJsonPath('data.0.outstanding.receivable', '0.00')
            ->assertJsonPath('data.0.lifetime.billed', '0.00')
            ->assertJsonPath('data.0.activity.last_transaction_at', null)
            ->assertJsonPath('data.0.activity.transaction_count', 0);
    }

    #[Test]
    public function amounts_are_sent_as_strings_never_json_numbers(): void
    {
        // A JSON number is parsed straight back into a float by every client
        // that receives it, which is the one thing the ledger's arithmetic
        // exists to avoid.
        $party = $this->party();

        $this->postSimpleJournal(
            $this->tenant, SystemAccount::Receivables, SystemAccount::Sales, '0.10', party: $party
        );

        $outstanding = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties?with_position=1')
            ->assertOk()
            ->json('data.0.outstanding');

        $this->assertIsString($outstanding['receivable']);
        $this->assertSame('0.10', $outstanding['receivable']);
    }

    #[Test]
    public function it_filters_by_role_search_and_status(): void
    {
        $this->party(['name' => 'Alpha Motors', 'roles' => ['customer']]);
        $this->party(['name' => 'Delta Copper', 'roles' => ['vendor']]);
        $this->party(['name' => 'Gamma Both', 'roles' => ['customer', 'vendor']]);
        $this->party(['name' => 'Old Supplier', 'roles' => ['vendor'], 'is_active' => false]);

        $vendors = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties?role=vendor&is_active=1')
            ->assertOk()
            ->json('data.*.name');

        $this->assertEqualsCanonicalizing(['Delta Copper', 'Gamma Both'], $vendors);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties?search=Copper')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Delta Copper');
    }

    #[Test]
    public function it_publishes_the_roles_and_the_position_each_implies(): void
    {
        $roles = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/parties/meta')
            ->assertOk()
            ->json('data.roles');

        $this->assertCount(count(PartyRole::cases()), $roles);

        $byValue = collect($roles)->keyBy('value');

        $this->assertSame('Receivable', $byValue['customer']['position_label']);
        $this->assertSame('debit', $byValue['customer']['normal_balance']);
        $this->assertSame('Payable', $byValue['vendor']['position_label']);
        $this->assertSame('credit', $byValue['vendor']['normal_balance']);
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_creates_a_party_and_derives_the_state_code(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/parties', [
                'name' => 'Bharat Winding Works',
                'roles' => ['customer', 'vendor'],
                'gstin' => '27aaaaa0000a1z5',
                'phone' => '9876543210',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Bharat Winding Works')
            // Upper-cased on the way in, so a GSTIN typed in lower case is
            // accepted rather than failing a regex the user cannot see.
            ->assertJsonPath('data.gstin', '27AAAAA0000A1Z5')
            ->assertJsonPath('data.state_code', '27')
            ->assertJsonPath('data.is_active', true);
    }

    #[Test]
    public function a_party_needs_at_least_one_role(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/parties', ['name' => 'Nobody In Particular', 'roles' => []])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function a_malformed_gstin_is_refused(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/parties', [
                'name' => 'Wrong Tax Id',
                'roles' => ['customer'],
                'gstin' => 'NOT-A-GSTIN-XX',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function a_duplicate_name_is_refused_with_an_explanation(): void
    {
        $this->party(['name' => 'Sharma Traders']);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/parties', ['name' => 'Sharma Traders', 'roles' => ['customer']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PARTY_NAME_TAKEN');
    }

    #[Test]
    public function a_duplicate_gstin_is_accepted_and_flagged(): void
    {
        $this->party(['name' => 'Verma Motors (Pune)', 'gstin' => '27AAAAA0000A1Z5', 'state_code' => '27']);

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/parties', [
                'name' => 'Verma Motors (Nashik)',
                'roles' => ['customer'],
                'gstin' => '27AAAAA0000A1Z5',
            ])
            // Created, not refused: a second branch files under one GSTIN, and
            // refusing it would make the product wrong about a real arrangement.
            ->assertCreated();

        $response->assertJsonPath('meta.warnings.0.code', 'PARTY_GSTIN_DUPLICATE');

        $this->assertSame(
            ['Verma Motors (Pune)'],
            collect($response->json('meta.warnings.0.party_ids'))
                ->map(fn (int $id) => $this->actingForTenant($this->tenant, fn () => Party::find($id)->name))
                ->all(),
        );
    }

    #[Test]
    public function a_patch_never_blanks_a_field_it_did_not_mention(): void
    {
        $party = $this->party([
            'name' => 'Alpha Motors',
            'gstin' => '27AAAAA0000A1Z5',
            'state_code' => '27',
            'phone' => '9876543210',
            'address' => 'Plot 14, MIDC',
        ]);

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/parties/{$party->id}", ['name' => 'Alpha Motors Pvt Ltd'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Alpha Motors Pvt Ltd')
            ->assertJsonPath('data.gstin', '27AAAAA0000A1Z5')
            ->assertJsonPath('data.phone', '9876543210')
            ->assertJsonPath('data.address', 'Plot 14, MIDC');
    }

    #[Test]
    public function a_party_can_be_archived_and_restored(): void
    {
        $party = $this->party();

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/parties/{$party->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/parties/{$party->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    #[Test]
    public function deleting_a_party_with_transactions_is_refused_over_the_wire(): void
    {
        $party = $this->party();

        $this->postSimpleJournal(
            $this->tenant, SystemAccount::Receivables, SystemAccount::Sales, '5000.00', party: $party
        );

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/parties/{$party->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PARTY_IN_USE')
            ->assertJsonPath('error.details.transaction_count', 1);
    }

    /* ---------------------------------------------------------------------
     | The statement
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_returns_a_party_statement_with_a_running_balance(): void
    {
        $party = $this->party();

        $this->postSimpleJournal(
            $this->tenant, SystemAccount::Receivables, SystemAccount::Sales, '10000.00',
            date: '2026-06-01', party: $party
        );

        $this->postSimpleJournal(
            $this->tenant, SystemAccount::Cash, SystemAccount::Receivables, '4000.00',
            date: '2026-06-10', party: $party
        );

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/parties/{$party->id}/ledger")
            ->assertOk();

        $response->assertJsonPath('meta.party.name', $party->name)
            ->assertJsonPath('meta.opening_balance', '0.00')
            ->assertJsonPath('meta.closing_balance', '6000.00')
            ->assertJsonPath('meta.outstanding.receivable', '6000.00')
            ->assertJsonPath('meta.outstanding.net', '6000.00')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.running_balance', '10000.00')
            ->assertJsonPath('data.1.running_balance', '6000.00');
    }

    #[Test]
    public function the_statement_needs_the_ledger_grant_not_just_the_party_one(): void
    {
        // Adding the customer at the counter and reading what they owe are
        // different authorities. DATA_ENTRY holds the first and not the second.
        [$tenant, $clerk] = $this->tenantWithUser(
            [['READ', 'PARTIES'], ['WRITE', 'PARTIES']],
            roleName: 'Clerk Role',
        );

        $party = $this->actingForTenant($tenant, fn () => Party::factory()->create());

        $this->withHeaders($this->authHeader($clerk))
            ->getJson("/api/v1/parties/{$party->id}")
            ->assertOk();

        $this->withHeaders($this->authHeader($clerk))
            ->getJson("/api/v1/parties/{$party->id}/ledger")
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | Attribution through the journal endpoint
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_journal_entry_can_name_a_counterparty(): void
    {
        $party = $this->party();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', [
                'date' => '2026-06-15',
                'post' => true,
                'party_id' => $party->id,
                'lines' => [
                    ['account_id' => $this->accountId(SystemAccount::Receivables), 'debit' => '2500.00'],
                    ['account_id' => $this->accountId(SystemAccount::Sales), 'credit' => '2500.00'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.party_id', $party->id);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/parties/{$party->id}/ledger")
            ->assertOk()
            ->assertJsonPath('meta.outstanding.receivable', '2500.00');
    }

    #[Test]
    public function a_transaction_cannot_name_another_workshops_party(): void
    {
        $other = Tenant::factory()->create();
        $theirs = $this->actingForTenant($other, fn () => Party::factory()->create());

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', [
                'date' => '2026-06-15',
                'post' => true,
                'party_id' => $theirs->id,
                'lines' => [
                    ['account_id' => $this->accountId(SystemAccount::Receivables), 'debit' => '2500.00'],
                    ['account_id' => $this->accountId(SystemAccount::Sales), 'credit' => '2500.00'],
                ],
            ])
            // The tenant scope alone does this: a foreign id simply does not
            // resolve, so a transaction cannot be attributed across a boundary.
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PARTY_UNKNOWN');
    }

    #[Test]
    public function a_transaction_cannot_name_an_archived_party(): void
    {
        $party = $this->party(['is_active' => false]);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', [
                'date' => '2026-06-15',
                'post' => true,
                'party_id' => $party->id,
                'lines' => [
                    ['account_id' => $this->accountId(SystemAccount::Receivables), 'debit' => '100.00'],
                    ['account_id' => $this->accountId(SystemAccount::Sales), 'credit' => '100.00'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PARTY_ARCHIVED');
    }

    #[Test]
    public function transactions_can_be_filtered_to_one_party(): void
    {
        $mine = $this->party(['name' => 'Alpha Motors']);
        $other = $this->party(['name' => 'Beta Rewinding']);

        $this->postSimpleJournal(
            $this->tenant, SystemAccount::Receivables, SystemAccount::Sales, '100.00', party: $mine
        );

        $this->postSimpleJournal(
            $this->tenant, SystemAccount::Receivables, SystemAccount::Sales, '200.00', party: $other
        );

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions?party_id={$mine->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.party.name', 'Alpha Motors');
    }

    /* ---------------------------------------------------------------------
     | Permissions and tenancy
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_requires_the_parties_permission(): void
    {
        [, $outsider] = $this->tenantWithUser([['READ', 'ACCOUNTS']], roleName: 'No Parties Role');

        $this->withHeaders($this->authHeader($outsider))
            ->getJson('/api/v1/parties')
            ->assertForbidden();
    }

    #[Test]
    public function another_workshops_party_is_not_reachable(): void
    {
        $other = Tenant::factory()->create();
        $theirs = $this->actingForTenant($other, fn () => Party::factory()->create());

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/parties/{$theirs->id}")
            ->assertNotFound();

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/parties/{$theirs->id}", ['name' => 'Hijacked'])
            ->assertNotFound();
    }
}
