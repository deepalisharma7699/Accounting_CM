<?php

namespace Tests\Feature\Accounting;

use App\Enums\BalanceSide;
use App\Enums\SystemAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Reading the books: an account's ledger, and the trial balance.
 *
 * Every number asserted here is derived from `journal_entries` at the moment it
 * is asked for. Nothing is stored, so nothing can drift.
 */
class LedgerTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithLedger, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([['*', '*']]);
    }

    /* ---------------------------------------------------------------------
     | The trial balance
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_untouched_workshop_reconciles_at_zero(): void
    {
        $trial = $this->actingForTenant($this->tenant, fn () => $this->ledger()->trialBalance());

        // Empty is the correct answer for a workshop that has posted nothing —
        // not an error, and emphatically not another workshop's numbers.
        $this->assertSame([], $trial['rows']);
        $this->assertSame('0.00', $trial['totals']['debit']->amount());
        $this->assertSame('0.00', $trial['totals']['credit']->amount());
        $this->assertTrue($trial['is_balanced']);
    }

    #[Test]
    public function it_lists_every_account_that_has_been_posted_to_and_no_others(): void
    {
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '5000.00');

        $trial = $this->actingForTenant($this->tenant, fn () => $this->ledger()->trialBalance());

        $names = array_map(fn (array $row) => $row['account']->name, $trial['rows']);

        $this->assertEqualsCanonicalizing(['Cash in Hand', 'Sales'], $names);
        // Thirteen other accounts exist and are untouched; a trial balance
        // showing them all as zero is noise.
        $this->assertCount(2, $trial['rows']);
    }

    #[Test]
    public function each_account_reports_its_net_balance_on_the_correct_side(): void
    {
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '5000.00');
        $this->postSimpleJournal($this->tenant, SystemAccount::MiscExpense, SystemAccount::Cash, '1200.00');

        $rows = collect($this->actingForTenant($this->tenant, fn () => $this->ledger()->trialBalance())['rows'])
            ->keyBy(fn (array $row) => $row['account']->system_key->value);

        // Cash: ₹5,000 in, ₹1,200 out. An asset, so the balance sits on the
        // debit side.
        $this->assertSame('5000.00', $rows['cash_in_hand']['debit']->amount());
        $this->assertSame('1200.00', $rows['cash_in_hand']['credit']->amount());
        $this->assertSame('3800.00', $rows['cash_in_hand']['balance']->amount());
        $this->assertSame(BalanceSide::Debit, $rows['cash_in_hand']['balance_side']);

        // Sales is credit-normal, so its balance sits on the credit side.
        $this->assertSame('5000.00', $rows['sales']['balance']->amount());
        $this->assertSame(BalanceSide::Credit, $rows['sales']['balance_side']);

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function an_account_holding_the_opposite_of_its_normal_balance_is_reported_honestly(): void
    {
        // An overdrawn bank account is an asset with a credit balance. Forcing
        // it onto the debit side as a negative number is how a trial balance
        // stops adding up.
        $this->postSimpleJournal($this->tenant, SystemAccount::MiscExpense, SystemAccount::Bank, '2500.00');

        $rows = collect($this->actingForTenant($this->tenant, fn () => $this->ledger()->trialBalance())['rows'])
            ->keyBy(fn (array $row) => $row['account']->system_key->value);

        $this->assertSame(BalanceSide::Credit, $rows['bank_account']['balance_side']);
        $this->assertSame('2500.00', $rows['bank_account']['balance']->amount());
        // Signed against its own normal side, the same position reads -2500.
        $this->assertSame('-2500.00', $rows['bank_account']['signed_balance']->amount());

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function the_trial_balance_respects_a_period(): void
    {
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '1000.00', date: '2026-04-15');
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '2000.00', date: '2026-05-20');

        $april = $this->actingForTenant(
            $this->tenant,
            fn () => $this->ledger()->trialBalance('2026-04-01', '2026-04-30')
        );

        $this->assertSame('1000.00', $april['totals']['debit']->amount());
        $this->assertTrue($april['is_balanced']);

        // A period that slices through the books still balances, because every
        // transaction inside it is itself balanced.
        $both = $this->actingForTenant(
            $this->tenant,
            fn () => $this->ledger()->trialBalance('2026-04-01', '2026-05-31')
        );

        $this->assertSame('3000.00', $both['totals']['debit']->amount());
        $this->assertTrue($both['is_balanced']);
    }

    #[Test]
    public function one_workshops_trial_balance_never_shows_anothers_numbers(): void
    {
        $other = Tenant::factory()->create();

        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00');
        $this->postSimpleJournal($other, SystemAccount::Cash, SystemAccount::Sales, '99999.00');

        $mine = $this->actingForTenant($this->tenant, fn () => $this->ledger()->trialBalance());

        $this->assertSame('100.00', $mine['totals']['debit']->amount());
    }

    /* ---------------------------------------------------------------------
     | One account's ledger
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_ledger_carries_a_running_balance_in_date_order(): void
    {
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '1000.00', date: '2026-06-01');
        $this->postSimpleJournal($this->tenant, SystemAccount::MiscExpense, SystemAccount::Cash, '250.00', date: '2026-06-05');
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::ServiceIncome, '500.50', date: '2026-06-10');

        $result = $this->actingForTenant($this->tenant, fn () => $this->ledger()->forAccount(
            $this->accountFor($this->tenant, SystemAccount::Cash)
        ));

        $running = collect($result['entries']->items())
            ->map(fn ($entry) => $entry->getAttribute('running_balance'))
            ->all();

        $this->assertSame(['1000.00', '750.00', '1250.50'], $running);
        $this->assertSame('0.00', $result['opening']->amount());
        $this->assertSame('1250.50', $result['closing']->amount());
        $this->assertSame('1500.50', $result['period']['debit']->amount());
        $this->assertSame('250.00', $result['period']['credit']->amount());
    }

    #[Test]
    public function a_date_filtered_ledger_opens_at_the_balance_brought_forward(): void
    {
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '1000.00', date: '2026-05-01');
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '400.00', date: '2026-06-02');

        $result = $this->actingForTenant($this->tenant, fn () => $this->ledger()->forAccount(
            $this->accountFor($this->tenant, SystemAccount::Cash),
            ['from' => '2026-06-01'],
        ));

        // Not zero: the running balance is cumulative from the first entry ever
        // posted, which is what makes a June ledger readable on its own.
        $this->assertSame('1000.00', $result['opening']->amount());
        $this->assertSame('1400.00', $result['closing']->amount());
        // The period totals, however, cover June alone.
        $this->assertSame('400.00', $result['period']['debit']->amount());
    }

    #[Test]
    public function the_running_balance_continues_correctly_onto_a_second_page(): void
    {
        for ($day = 1; $day <= 6; $day++) {
            $this->postSimpleJournal(
                $this->tenant,
                SystemAccount::Cash,
                SystemAccount::Sales,
                '100.00',
                date: '2026-06-0'.$day,
            );
        }

        $cash = $this->accountFor($this->tenant, SystemAccount::Cash);

        $secondPage = $this->actingForTenant($this->tenant, function () use ($cash) {
            request()->merge(['page' => 2]);

            return $this->ledger()->forAccount($cash, [], perPage: 4);
        });

        // Page 2 holds entries 5 and 6, so it opens at ₹400 and runs to ₹600 —
        // not restarting from zero, which is the classic paginated-ledger bug.
        $this->assertSame('400.00', $secondPage['opening']->amount());
        $this->assertSame(
            ['500.00', '600.00'],
            collect($secondPage['entries']->items())->map(fn ($e) => $e->getAttribute('running_balance'))->all(),
        );
    }

    #[Test]
    public function an_untouched_accounts_ledger_is_empty_rather_than_an_error(): void
    {
        $result = $this->actingForTenant($this->tenant, fn () => $this->ledger()->forAccount(
            $this->accountFor($this->tenant, SystemAccount::Inventory)
        ));

        $this->assertSame(0, $result['entries']->total());
        $this->assertSame('0.00', $result['opening']->amount());
        $this->assertSame('0.00', $result['closing']->amount());
    }

    #[Test]
    public function a_reversal_shows_in_the_ledger_as_a_second_line_not_a_deletion(): void
    {
        $original = $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '800.00');

        $this->actingForTenant($this->tenant, fn () => $this->engine()->reverse($original));

        $result = $this->actingForTenant($this->tenant, fn () => $this->ledger()->forAccount(
            $this->accountFor($this->tenant, SystemAccount::Cash)
        ));

        // Both lines are visible, and the account is back to nil. Someone
        // reading the ledger can see what happened and that it was undone.
        $this->assertSame(2, $result['entries']->total());
        $this->assertSame('0.00', $result['closing']->amount());
    }
}
