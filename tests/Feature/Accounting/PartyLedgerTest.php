<?php

namespace Tests\Feature\Accounting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Models\Party;
use App\Models\Tenant;
use App\Services\Accounting\PartyLedgerService;
use App\Services\Accounting\PartyService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * What a party owes, and what they are owed.
 *
 * Every figure here is derived from `journal_entries` on the spot, so the
 * question these tests actually answer is whether the derivation is right —
 * drift is impossible by construction, because there is nothing stored to drift
 * from. The reconciliation test at the bottom is the one that matters most: the
 * sum of every party's position must equal the control account it rolls up
 * into, which is the property that lets a party statement and a trial balance
 * be trusted against each other.
 */
class PartyLedgerTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithLedger, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function ledgerService(): PartyLedgerService
    {
        return app(PartyLedgerService::class);
    }

    private function party(string $name = 'Bharat Winding Works', string ...$roles): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'name' => $name,
            'roles' => $roles === [] ? [PartyRole::Customer->value] : $roles,
        ]));
    }

    /**
     * @param  array<int, array{0: SystemAccount, 1: string, 2: string}>  $lines
     */
    private function postFor(Party $party, array $lines, ?string $date = null): void
    {
        $this->actingForTenant($this->tenant, fn () => $this->engine()->post(
            $this->batchFor($this->tenant, $lines, $date, party: $party)
        ));
    }

    /** A sale on credit: the customer owes us. */
    private function invoice(Party $party, string $amount, ?string $date = null): void
    {
        $this->postFor($party, [
            [SystemAccount::Receivables, 'debit', $amount],
            [SystemAccount::Sales, 'credit', $amount],
        ], $date);
    }

    /** A receipt: they pay, and their outstanding falls. */
    private function receipt(Party $party, string $amount, ?string $date = null): void
    {
        $this->postFor($party, [
            [SystemAccount::Cash, 'debit', $amount],
            [SystemAccount::Receivables, 'credit', $amount],
        ], $date);
    }

    /** A purchase on credit: we owe the supplier. */
    private function bill(Party $party, string $amount, ?string $date = null): void
    {
        $this->postFor($party, [
            [SystemAccount::Inventory, 'debit', $amount],
            [SystemAccount::Payables, 'credit', $amount],
        ], $date);
    }

    /* ---------------------------------------------------------------------
     | The position
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_party_with_no_transactions_owes_nothing(): void
    {
        // Zero, not absent. A caller must never have to tell "nothing owed"
        // apart from "not fetched".
        $this->assertSame(
            ['receivable' => '0.00', 'payable' => '0.00', 'net' => '0.00'],
            $this->positionOf($this->tenant, $this->party()),
        );
    }

    #[Test]
    public function an_invoice_raises_the_receivable_by_exactly_its_amount(): void
    {
        $party = $this->party();

        $this->invoice($party, '11800.00');

        $this->assertSame('11800.00', $this->positionOf($this->tenant, $party)['receivable']);
        $this->assertBooksBalance($this->tenant, 'after invoicing a customer');
    }

    #[Test]
    public function a_receipt_reduces_the_outstanding_by_exactly_the_amount(): void
    {
        $party = $this->party();

        $this->invoice($party, '11800.00');
        $this->receipt($party, '5000.00');

        $this->assertSame('6800.00', $this->positionOf($this->tenant, $party)['receivable']);
        $this->assertBooksBalance($this->tenant, 'after a part payment');
    }

    #[Test]
    public function settling_in_full_leaves_nothing_outstanding(): void
    {
        $party = $this->party();

        $this->invoice($party, '11800.00');
        $this->receipt($party, '11800.00');

        $this->assertSame(
            ['receivable' => '0.00', 'payable' => '0.00', 'net' => '0.00'],
            $this->positionOf($this->tenant, $party),
        );
    }

    #[Test]
    public function overpaying_leaves_the_customer_in_credit(): void
    {
        // Reported as a negative receivable rather than being refused or
        // silently clamped: the money is in the bank and it is theirs, so the
        // books must say so. The alternative — forcing it onto the payable side
        // — would claim a supplier relationship that does not exist.
        $party = $this->party();

        $this->invoice($party, '5000.00');
        $this->receipt($party, '6000.00');

        $position = $this->positionOf($this->tenant, $party);

        $this->assertSame('-1000.00', $position['receivable']);
        $this->assertSame('-1000.00', $position['net']);
        $this->assertBooksBalance($this->tenant, 'after an overpayment');
    }

    /* ---------------------------------------------------------------------
     | Both roles at once
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_party_who_is_both_shows_one_combined_ledger(): void
    {
        $party = $this->party('Sharma Electricals', PartyRole::Customer->value, PartyRole::Vendor->value);

        $this->invoice($party, '40000.00');
        $this->bill($party, '38000.00');

        $position = $this->positionOf($this->tenant, $party);

        // Both sides are stated, and the net alongside them. Reporting only the
        // net would say "₹2,000" about a relationship with ₹78,000 moving
        // through it on separate terms.
        $this->assertSame('40000.00', $position['receivable']);
        $this->assertSame('38000.00', $position['payable']);
        $this->assertSame('2000.00', $position['net']);

        // One statement, not two: four lines across both control accounts.
        $ledger = $this->actingForTenant(
            $this->tenant,
            fn () => $this->ledgerService()->forParty($party->fresh())
        );

        $this->assertSame(2, $ledger['entries']->total());
        $this->assertSame('2000.00', $ledger['closing']->amount());
        $this->assertBooksBalance($this->tenant, 'for a party on both sides');
    }

    #[Test]
    public function the_ledger_does_not_depend_on_the_roles_on_the_record(): void
    {
        // The failure this prevents: scoping the read to the party's roles
        // would mean dropping the "vendor" tag emptied that half of their
        // ledger while the money stayed in the control account — a party
        // balance and a trial balance disagreeing, caused by editing a label.
        $party = $this->party('Sharma Electricals', PartyRole::Customer->value, PartyRole::Vendor->value);

        $this->invoice($party, '40000.00');
        $this->bill($party, '38000.00');

        $this->actingForTenant($this->tenant, fn () => app(PartyService::class)
            ->update($party->id, ['roles' => [PartyRole::Customer->value]]));

        $position = $this->positionOf($this->tenant, $party->fresh());

        $this->assertSame('38000.00', $position['payable'], 'Removing a role must not hide money.');
        $this->assertSame('2000.00', $position['net']);
    }

    /* ---------------------------------------------------------------------
     | The statement
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_statement_carries_a_running_balance_in_date_order(): void
    {
        $party = $this->party();

        $this->invoice($party, '10000.00', '2026-06-01');
        $this->receipt($party, '4000.00', '2026-06-10');
        $this->invoice($party, '2500.00', '2026-06-20');

        $ledger = $this->actingForTenant(
            $this->tenant,
            fn () => $this->ledgerService()->forParty($party)
        );

        $balances = collect($ledger['entries']->items())
            ->map(fn ($entry) => $entry->running_balance)
            ->all();

        $this->assertSame(['10000.00', '6000.00', '8500.00'], $balances);
        $this->assertSame('0.00', $ledger['opening']->amount());
        $this->assertSame('8500.00', $ledger['closing']->amount());
    }

    #[Test]
    public function a_filtered_period_opens_at_the_balance_brought_forward(): void
    {
        // Not zero: a June statement that opened at nothing would misstate the
        // position by everything that happened before June.
        $party = $this->party();

        $this->invoice($party, '10000.00', '2026-05-15');
        $this->invoice($party, '2500.00', '2026-06-05');

        $ledger = $this->actingForTenant($this->tenant, fn () => $this->ledgerService()->forParty(
            $party,
            ['from' => '2026-06-01', 'to' => '2026-06-30'],
        ));

        $this->assertSame('10000.00', $ledger['opening']->amount());
        $this->assertSame('12500.00', $ledger['closing']->amount());
        $this->assertSame(1, $ledger['entries']->total());
    }

    #[Test]
    public function the_second_page_continues_from_the_first(): void
    {
        $party = $this->party();

        for ($i = 0; $i < 5; $i++) {
            $this->invoice($party, '1000.00', '2026-06-0'.($i + 1));
        }

        // `opening` and `closing` bracket the *page*, not the whole statement:
        // page 1 of five ₹1,000 invoices runs 0 → 2,000.
        $page1 = $this->actingForTenant($this->tenant, fn () => $this->ledgerService()->forParty(
            $party,
            [],
            perPage: 2,
        ));

        $this->assertSame('0.00', $page1['opening']->amount());
        $this->assertSame('2000.00', $page1['closing']->amount());
        // The position, unlike the page, is always the whole story.
        $this->assertSame('5000.00', $page1['position']['net']->amount());

        // Page 2 must open at 2,000 — the running total of page 1 — rather than
        // restarting at zero.
        request()->merge(['page' => 2]);

        $page2 = $this->actingForTenant($this->tenant, fn () => $this->ledgerService()->forParty(
            $party,
            [],
            perPage: 2,
        ));

        $this->assertSame('2000.00', $page2['opening']->amount());
        $this->assertSame('4000.00', $page2['closing']->amount());
    }

    /* ---------------------------------------------------------------------
     | Reversal
     |-------------------------------------------------------------------- */

    #[Test]
    public function reversing_an_invoice_clears_the_party_outstanding(): void
    {
        // The reversal must carry the same party. If it did not, the control
        // account would net to zero while the party stayed permanently
        // in debt — the two disagreeing about the same money.
        $party = $this->party();

        $original = $this->actingForTenant($this->tenant, fn () => $this->engine()->post(
            $this->batchFor($this->tenant, [
                [SystemAccount::Receivables, 'debit', '7500.00'],
                [SystemAccount::Sales, 'credit', '7500.00'],
            ], party: $party)
        ));

        $this->assertSame('7500.00', $this->positionOf($this->tenant, $party)['receivable']);

        $reversal = $this->actingForTenant(
            $this->tenant,
            fn () => $this->engine()->reverse($original)
        );

        $this->assertSame($party->id, $reversal->party_id);
        $this->assertSame('0.00', $this->positionOf($this->tenant, $party)['receivable']);
        $this->assertBooksBalance($this->tenant, 'after reversing an invoice');
    }

    #[Test]
    public function an_archived_party_can_still_have_a_mistake_reversed(): void
    {
        // Archiving means "no new business", not "this error is permanent".
        $party = $this->party();

        $original = $this->actingForTenant($this->tenant, fn () => $this->engine()->post(
            $this->batchFor($this->tenant, [
                [SystemAccount::Receivables, 'debit', '3000.00'],
                [SystemAccount::Sales, 'credit', '3000.00'],
            ], party: $party)
        ));

        $this->actingForTenant($this->tenant, function () use ($party) {
            $party->is_active = false;
            $party->save();
        });

        $reversal = $this->actingForTenant($this->tenant, fn () => $this->engine()->reverse($original));

        $this->assertSame($party->id, $reversal->party_id);
        $this->assertSame('0.00', $this->positionOf($this->tenant, $party)['receivable']);
    }

    /* ---------------------------------------------------------------------
     | Drafts
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_draft_moves_no_party_balance(): void
    {
        // Drafts are not in journal_entries at all, so this holds structurally
        // rather than by a filter somebody has to remember.
        $party = $this->party();

        $this->actingForTenant($this->tenant, fn () => $this->engine()->draft(
            $this->batchFor($this->tenant, [
                [SystemAccount::Receivables, 'debit', '9999.00'],
                [SystemAccount::Sales, 'credit', '9999.00'],
            ], party: $party)
        ));

        $this->assertSame('0.00', $this->positionOf($this->tenant, $party)['receivable']);
    }

    /* ---------------------------------------------------------------------
     | The reconciliation that matters
     |-------------------------------------------------------------------- */

    #[Test]
    public function every_party_position_sums_to_its_control_account(): void
    {
        // The property the whole design rests on: a party ledger and the
        // control account are the same rows summed two ways, so they cannot
        // disagree. If this ever fails, either the derivation is wrong or
        // something reached journal_entries outside the posting engine.
        $customers = collect(['Alpha Motors', 'Beta Rewinding', 'Gamma Pumps'])
            ->map(fn (string $name) => $this->party($name));

        $suppliers = collect(['Delta Copper', 'Epsilon Bearings'])
            ->map(fn (string $name) => $this->party($name, PartyRole::Vendor->value));

        $customers->each(function (Party $party, int $index) {
            $this->invoice($party, sprintf('%d.50', 1000 * ($index + 1)));
            $this->receipt($party, sprintf('%d.25', 300 * ($index + 1)));
        });

        $suppliers->each(function (Party $party, int $index) {
            $this->bill($party, sprintf('%d.75', 2000 * ($index + 1)));
        });

        // An entry with no counterparty at all — a cash sale — which must land
        // in the control account's siblings and nowhere near a party.
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '999.99');

        $positions = $this->actingForTenant(
            $this->tenant,
            fn () => $this->ledgerService()->positionsFor($customers->merge($suppliers))
        );

        $receivable = Money::sum(array_map(
            fn (array $position) => Money::of($position['receivable']),
            $positions,
        ));

        $payable = Money::sum(array_map(
            fn (array $position) => Money::of($position['payable']),
            $positions,
        ));

        $this->assertSame(
            $this->balanceOf($this->tenant, SystemAccount::Receivables),
            $receivable->amount(),
            'The customers do not add up to Sundry Debtors.',
        );

        $this->assertSame(
            $this->balanceOf($this->tenant, SystemAccount::Payables),
            $payable->amount(),
            'The suppliers do not add up to Sundry Creditors.',
        );

        $this->assertBooksBalance($this->tenant, 'after a mixed run of party transactions');
    }

    /* ---------------------------------------------------------------------
     | Tenancy
     |-------------------------------------------------------------------- */

    #[Test]
    public function one_workshop_never_sees_another_partys_position(): void
    {
        $other = Tenant::factory()->create();

        $mine = $this->party('Shared Name');
        $this->invoice($mine, '5000.00');

        $theirs = $this->actingForTenant($other, fn () => Party::factory()->create(['name' => 'Shared Name']));

        $this->actingForTenant($other, fn () => $this->engine()->post(
            $this->batchFor($other, [
                [SystemAccount::Receivables, 'debit', '99999.00'],
                [SystemAccount::Sales, 'credit', '99999.00'],
            ], party: $theirs)
        ));

        $this->assertSame('5000.00', $this->positionOf($this->tenant, $mine)['receivable']);
        $this->assertSame('99999.00', $this->positionOf($other, $theirs)['receivable']);

        $this->assertBooksBalance($this->tenant);
        $this->assertBooksBalance($other);
    }
}
