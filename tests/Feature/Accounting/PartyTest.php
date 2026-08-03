<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Exceptions\Accounting\PartyInUseException;
use App\Exceptions\ConflictException;
use App\Models\Party;
use App\Models\Tenant;
use App\Services\Accounting\PartyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The party record itself: roles, naming, tax identity, archiving and deletion.
 *
 * What a party *owes* is {@see PartyLedgerTest}'s subject.
 */
class PartyTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithLedger, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function service(): PartyService
    {
        return app(PartyService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createParty(array $overrides = []): Party
    {
        return $this->actingForTenant($this->tenant, fn () => $this->service()->create(array_merge([
            'name' => 'Bharat Winding Works',
            'roles' => [PartyRole::Customer->value],
        ], $overrides)));
    }

    /* ---------------------------------------------------------------------
     | Roles
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_party_can_hold_both_roles_at_once(): void
    {
        $party = $this->createParty([
            'roles' => [PartyRole::Vendor->value, PartyRole::Customer->value],
        ]);

        $this->assertTrue($party->isCustomer());
        $this->assertTrue($party->isVendor());
    }

    #[Test]
    public function roles_are_stored_in_a_canonical_order(): void
    {
        // Two parties described equivalently must be stored identically, or a
        // comparison of the JSON column reports a difference that is not one.
        $first = $this->createParty([
            'name' => 'A Traders',
            'roles' => [PartyRole::Vendor->value, PartyRole::Customer->value],
        ]);

        $second = $this->createParty([
            'name' => 'B Traders',
            'roles' => [PartyRole::Customer->value, PartyRole::Vendor->value],
        ]);

        $this->assertSame(['customer', 'vendor'], $first->roles);
        $this->assertSame($first->roles, $second->roles);
    }

    #[Test]
    public function a_party_must_hold_at_least_one_role(): void
    {
        // A party in neither list would still accumulate a balance — present
        // in the books and absent from every screen that could show it.
        $this->expectException(ConflictException::class);

        $this->createParty(['roles' => []]);
    }

    #[Test]
    public function unknown_roles_are_discarded_rather_than_stored(): void
    {
        $party = $this->createParty(['roles' => ['customer', 'landlord']]);

        $this->assertSame(['customer'], $party->roles);
    }

    #[Test]
    public function the_role_filter_matches_membership_not_equality(): void
    {
        // The counterparty who is both must appear in both lists, or the one
        // record modelling both sides of a relationship vanishes from each.
        $this->actingForTenant($this->tenant, function () {
            Party::factory()->customer()->create(['name' => 'Only Customer']);
            Party::factory()->vendor()->create(['name' => 'Only Vendor']);
            Party::factory()->both()->create(['name' => 'Both Ways']);

            $customers = collect($this->service()->paginate(['role' => 'customer'], 25)->items())
                ->pluck('name')->all();

            $vendors = collect($this->service()->paginate(['role' => 'vendor'], 25)->items())
                ->pluck('name')->all();

            $this->assertEqualsCanonicalizing(['Only Customer', 'Both Ways'], $customers);
            $this->assertEqualsCanonicalizing(['Only Vendor', 'Both Ways'], $vendors);
        });
    }

    /* ---------------------------------------------------------------------
     | Naming
     |-------------------------------------------------------------------- */

    #[Test]
    public function two_parties_cannot_share_a_name(): void
    {
        $this->createParty(['name' => 'Sharma Traders']);

        $this->expectException(ConflictException::class);

        $this->createParty(['name' => 'Sharma Traders']);
    }

    #[Test]
    public function the_same_name_is_free_in_another_workshop(): void
    {
        $other = Tenant::factory()->create();

        $this->createParty(['name' => 'Sharma Traders']);

        $mine = $this->actingForTenant($other, fn () => $this->service()->create([
            'name' => 'Sharma Traders',
            'roles' => ['customer'],
        ]));

        $this->assertNotNull($mine->id);
        $this->assertSame($other->id, $mine->tenant_id);
    }

    #[Test]
    public function renaming_to_an_existing_name_is_refused(): void
    {
        $this->createParty(['name' => 'Sharma Traders']);
        $second = $this->createParty(['name' => 'Verma Motors']);

        $this->expectException(ConflictException::class);

        $this->actingForTenant($this->tenant, fn () => $this->service()->update($second->id, [
            'name' => 'Sharma Traders',
        ]));
    }

    /* ---------------------------------------------------------------------
     | GSTIN
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_gstin_fills_the_state_code(): void
    {
        // M9 decides CGST+SGST versus IGST from this, so it is derived rather
        // than accepted from the client.
        $party = $this->createParty(['gstin' => '27AAAAA0000A1Z5']);

        $this->assertSame('27AAAAA0000A1Z5', $party->gstin);
        $this->assertSame('27', $party->state_code);
    }

    #[Test]
    public function clearing_the_gstin_clears_the_state_code_with_it(): void
    {
        $party = $this->createParty(['gstin' => '27AAAAA0000A1Z5']);

        $party = $this->actingForTenant(
            $this->tenant,
            fn () => $this->service()->update($party->id, ['gstin' => null])
        );

        $this->assertNull($party->gstin);
        $this->assertNull($party->state_code);
    }

    #[Test]
    public function a_duplicate_gstin_is_allowed_and_reported(): void
    {
        // Branches of one business file under one GSTIN, so the second is
        // legitimate — but far more often it is the same party entered twice,
        // which splits one balance in half. Hence: allowed, and surfaced.
        $first = $this->createParty(['name' => 'Verma Motors (Pune)', 'gstin' => '27AAAAA0000A1Z5']);
        $second = $this->createParty(['name' => 'Verma Motors (Nashik)', 'gstin' => '27AAAAA0000A1Z5']);

        $this->assertSame($first->gstin, $second->gstin);

        $others = $this->actingForTenant(
            $this->tenant,
            fn () => $this->service()->othersSharingGstin('27AAAAA0000A1Z5', $second->id)
        );

        $this->assertSame(['Verma Motors (Pune)'], $others->pluck('name')->all());
    }

    #[Test]
    public function a_shared_gstin_is_not_reported_across_workshops(): void
    {
        $other = Tenant::factory()->create();

        $this->createParty(['gstin' => '27AAAAA0000A1Z5']);

        $others = $this->actingForTenant(
            $other,
            fn () => $this->service()->othersSharingGstin('27AAAAA0000A1Z5')
        );

        $this->assertTrue($others->isEmpty());
    }

    /* ---------------------------------------------------------------------
     | Archiving and deletion
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_party_with_no_transactions_can_be_deleted(): void
    {
        $party = $this->createParty();

        $this->actingForTenant($this->tenant, fn () => $this->service()->delete($party->id));

        $this->assertNull($this->actingForTenant($this->tenant, fn () => Party::find($party->id)));
    }

    #[Test]
    public function deleting_a_party_with_ledger_entries_is_refused(): void
    {
        $party = $this->createParty();

        $this->postAgainstParty($party, SystemAccount::Receivables, SystemAccount::Sales, '5000.00');

        try {
            $this->actingForTenant($this->tenant, fn () => $this->service()->delete($party->id));
            $this->fail('Deleting a party with entries should have been refused.');
        } catch (PartyInUseException $e) {
            $this->assertSame('PARTY_IN_USE', $e->errorCode());
            $this->assertSame(1, $e->details()['transaction_count']);
            // The refusal names the alternative rather than being a dead end.
            $this->assertTrue($e->details()['archive_instead']);
        }

        $this->assertNotNull($this->actingForTenant($this->tenant, fn () => Party::find($party->id)));
    }

    #[Test]
    public function deleting_a_party_named_only_by_a_draft_is_refused(): void
    {
        // Nothing reached the ledger, but the draft would be left pointing at
        // a party that no longer exists and could never be posted.
        $party = $this->createParty();

        $this->actingForTenant($this->tenant, fn () => $this->engine()->draft(
            $this->batchFor($this->tenant, [
                [SystemAccount::Receivables, 'debit', '900.00'],
                [SystemAccount::Sales, 'credit', '900.00'],
            ], party: $party)
        ));

        $this->expectException(PartyInUseException::class);

        $this->actingForTenant($this->tenant, fn () => $this->service()->delete($party->id));
    }

    #[Test]
    public function an_archived_party_keeps_everything_already_posted(): void
    {
        $party = $this->createParty();

        $this->postAgainstParty($party, SystemAccount::Receivables, SystemAccount::Sales, '5000.00');

        $this->actingForTenant(
            $this->tenant,
            fn () => $this->service()->update($party->id, ['is_active' => false])
        );

        $this->assertSame('5000.00', $this->positionOf($this->tenant, $party)['receivable']);
        $this->assertBooksBalance($this->tenant, 'after archiving a party');
    }

    /* ---------------------------------------------------------------------
     | Tenancy
     |-------------------------------------------------------------------- */

    #[Test]
    public function parties_are_scoped_to_their_workshop(): void
    {
        $other = Tenant::factory()->create();

        $this->createParty(['name' => 'Mine']);

        $this->actingForTenant($other, fn () => Party::factory()->create(['name' => 'Theirs']));

        $mine = $this->actingForTenant($this->tenant, fn () => Party::pluck('name')->all());
        $theirs = $this->actingForTenant($other, fn () => Party::pluck('name')->all());

        $this->assertSame(['Mine'], $mine);
        $this->assertSame(['Theirs'], $theirs);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    private function postAgainstParty(
        Party $party,
        SystemAccount $debit,
        SystemAccount $credit,
        string $amount,
        ?string $date = null,
    ): void {
        $this->actingForTenant($this->tenant, fn () => $this->engine()->post(
            $this->batchFor($this->tenant, [
                [$debit, 'debit', $amount],
                [$credit, 'credit', $amount],
            ], $date, party: $party)
        ));
    }
}
