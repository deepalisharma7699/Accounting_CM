<?php

namespace Tests\Feature\Reporting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountService;
use App\Services\Reporting\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * M12 — reading the books at every zoom level.
 *
 * The roadmap's three checks are each a test here:
 *
 *   * every report reconciles against the trial balance;
 *   * reports respect the financial year from M2.2;
 *   * a report for a workshop with no data shows zero — **not** an error, and
 *     never another workshop's numbers.
 */
class ReportTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'LEDGER'], ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'],
            ['UPDATE', 'TRANSACTIONS'], ['READ', 'ACCOUNTS'], ['READ', 'PARTIES'],
            ['READ', 'ITEMS'], ['READ', 'STOCK'],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     | ------------------------------------------------------------------- */

    private function customer(string $name = 'Sharma Motors'): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'name' => $name,
            'roles' => [PartyRole::Customer->value],
            'gstin' => null,
        ]));
    }

    private function vendor(string $name = 'Kohli Traders'): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'name' => $name,
            'roles' => [PartyRole::Vendor->value],
            'gstin' => null,
        ]));
    }

    /**
     * A purchase then a sale of the same bearing — the shape every report below
     * is measured against.
     *
     * @return array{0: ItemVariant, 1: Party}
     */
    private function tradeOneBearing(?string $date = null): array
    {
        $variant = $this->variantFor($this->tenant, 'part');
        $vendor = $this->vendor();
        $customer = $this->customer();

        $this->actingForTenant($this->tenant, function () use ($variant, $vendor, $customer, $date) {
            $on = $date ?? now()->toDateString();

            $this->engine()->postComposed(TransactionType::Purchase, [
                'date' => $on,
                'party_id' => $vendor->id,
                'items' => [[
                    'item_id' => $variant->item_id,
                    'variant_id' => $variant->id,
                    'quantity' => '10',
                    'unit_price' => '100.00',
                ]],
                'payments' => [],
            ], $this->owner);

            $this->engine()->postComposed(TransactionType::Sale, [
                'date' => $on,
                'party_id' => $customer->id,
                'items' => [[
                    'item_id' => $variant->item_id,
                    'variant_id' => $variant->id,
                    'quantity' => '4',
                    'unit_price' => '250.00',
                ]],
                'payments' => [],
            ], $this->owner);
        });

        return [$variant, $customer];
    }

    private function reports(): ReportService
    {
        return app(ReportService::class);
    }

    /* ---------------------------------------------------------------------
     | The day book
     | ------------------------------------------------------------------- */

    #[Test]
    public function the_day_book_lists_every_voucher_forwards_with_its_lines(): void
    {
        $this->tradeOneBearing();

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/day-book')
            ->assertOk();

        $this->assertSame(2, $response->json('meta.pagination.total'));

        // Forwards — the order the day happened in, which is the opposite of the
        // transaction list and right for both.
        $this->assertSame('purchase', $response->json('data.0.type'));
        $this->assertSame('sale', $response->json('data.1.type'));

        // Every line of every voucher, which is what makes it a day book rather
        // than a list.
        $this->assertNotEmpty($response->json('data.0.lines'));
    }

    #[Test]
    public function the_day_book_reconciles_against_the_trial_balance(): void
    {
        $this->tradeOneBearing();

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/day-book')
            ->assertOk();

        $this->assertTrue($response->json('meta.totals.is_balanced'));
        $this->assertSame(
            $response->json('meta.totals.debit'),
            $response->json('meta.totals.credit'),
        );

        $trial = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/ledger/trial-balance')
            ->assertOk();

        $this->assertSame($trial->json('meta.totals.debit'), $response->json('meta.totals.debit'));
    }

    #[Test]
    public function a_draft_never_appears_in_the_day_book(): void
    {
        // Structurally rather than by a filter: a draft has no journal entries,
        // so a day book that included one would show a voucher with no lines.
        $this->actingForTenant($this->tenant, fn () => $this->engine()->draft(
            $this->batchFor($this->tenant, [
                [SystemAccount::Cash, 'debit', '500.00'],
                [SystemAccount::Sales, 'credit', '500.00'],
            ]),
            $this->owner,
        ));

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/day-book')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    }

    /* ---------------------------------------------------------------------
     | Profit and loss
     | ------------------------------------------------------------------- */

    #[Test]
    public function the_profit_and_loss_separates_gross_margin_from_overheads(): void
    {
        // Four bearings sold at ₹250 that cost ₹100 each: ₹1,000 revenue,
        // ₹400 of stock, ₹600 gross. Then ₹200 of rent, which is not a cost of
        // sales and must not be allowed to look like one.
        $this->tradeOneBearing();

        $this->actingForTenant($this->tenant, fn () => $this->engine()->postComposed(
            TransactionType::Expense,
            [
                'date' => now()->toDateString(),
                'amount' => '200.00',
                'payments' => [['mode' => 'cash', 'amount' => '200.00']],
            ],
            $this->owner,
        ));

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss')
            ->assertOk();

        $this->assertSame('1000.00', $response->json('meta.totals.revenue'));
        $this->assertSame('400.00', $response->json('meta.totals.cost_of_sales'));
        $this->assertSame('600.00', $response->json('meta.totals.gross_margin'));
        $this->assertSame('200.00', $response->json('meta.totals.overheads'));
        $this->assertSame('400.00', $response->json('meta.totals.net'));

        // COGS is in cost_of_sales and the expense is in overheads. Adding the
        // two together would say neither: an 8% gross margin is a pricing
        // problem and a 40% one that still loses money is a rent problem.
        $this->assertCount(1, $response->json('data.cost_of_sales'));
        $this->assertCount(1, $response->json('data.overheads'));
    }

    #[Test]
    public function the_profit_and_loss_carries_no_balance_sheet_account(): void
    {
        $this->tradeOneBearing();

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss')
            ->assertOk();

        $types = collect([
            ...$response->json('data.income'),
            ...$response->json('data.cost_of_sales'),
            ...$response->json('data.overheads'),
        ])->pluck('account.type')->unique()->all();

        // Assets, liabilities and equity carry forward year to year; they are
        // closed into the result rather than being part of it.
        $this->assertEmpty(array_diff($types, ['income', 'expense']));
    }

    #[Test]
    public function a_workshops_own_expense_account_appears_without_being_named_anywhere(): void
    {
        // The statement is assembled from the chart rather than from a fixed
        // list, so an account the workshop added is not silently omitted.
        $rent = $this->actingForTenant($this->tenant, fn () => app(
            ChartOfAccountService::class
        )->create(['code' => '5300', 'name' => 'Workshop Rent', 'type' => 'expense']));

        $this->actingForTenant($this->tenant, fn () => $this->engine()->postComposed(
            TransactionType::Expense,
            [
                'date' => now()->toDateString(),
                'account_id' => $rent->id,
                'amount' => '9000.00',
                'payments' => [['mode' => 'bank', 'amount' => '9000.00']],
            ],
            $this->owner,
        ));

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss')
            ->assertOk();

        $this->assertSame('Workshop Rent', $response->json('data.overheads.0.account.name'));
        $this->assertSame('9000.00', $response->json('meta.totals.overheads'));
    }

    /* ---------------------------------------------------------------------
     | GST
     | ------------------------------------------------------------------- */

    #[Test]
    public function the_gst_summary_reports_output_and_input_rate_by_rate(): void
    {
        $this->tradeOneBearing();

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/gst')
            ->assertOk();

        $this->assertSame('1000.00', $response->json('data.output.taxable'));
        $this->assertSame('1000.00', $response->json('data.input.taxable'));

        // The rate breakdown — the whole reason this reads bill lines rather
        // than the ledger. Phase 1 has one GST account per direction, so the
        // journal knows the tax but not the rate it was struck at.
        $this->assertNotEmpty($response->json('data.output.rates'));
        $this->assertArrayHasKey('rate', $response->json('data.output.rates.0'));
    }

    #[Test]
    public function the_gst_summary_reconciles_against_the_tax_accounts(): void
    {
        $this->tradeOneBearing();

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/gst')
            ->assertOk();

        // The bill lines and the ledger are two readings of one posting, so they
        // agree — and the report says so rather than leaving a reader to
        // discover it on a return.
        $this->assertTrue($response->json('meta.reconciliation.agrees'));
        $this->assertSame('0.00', $response->json('meta.reconciliation.output_difference'));
        $this->assertSame(
            $response->json('data.output.tax'),
            $response->json('meta.reconciliation.ledger_output'),
        );
    }

    #[Test]
    public function tax_reaching_an_account_without_a_bill_behind_it_is_surfaced(): void
    {
        // A manual journal into GST Output. M4 deliberately allows this — it is
        // the correction mechanism for everything else — so the report's job is
        // to make it visible rather than to refuse or hide it.
        $this->tradeOneBearing();

        $this->postSimpleJournal(
            $this->tenant,
            SystemAccount::Cash,
            SystemAccount::GstOutput,
            '500.00',
            actor: $this->owner,
        );

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/gst')
            ->assertOk();

        $this->assertFalse($response->json('meta.reconciliation.agrees'));
        $this->assertSame('500.00', $response->json('meta.reconciliation.output_difference'));

        // And the books still balance. A difference here is a reconciliation
        // question, never a broken ledger.
        $this->assertBooksBalance($this->tenant, 'after a manual journal into a tax account');
    }

    /* ---------------------------------------------------------------------
     | The draft worklist
     | ------------------------------------------------------------------- */

    #[Test]
    public function the_worklist_flags_a_draft_that_has_gone_stale(): void
    {
        $fresh = $this->actingForTenant($this->tenant, fn () => $this->engine()->draft(
            $this->batchFor($this->tenant, [
                [SystemAccount::Cash, 'debit', '500.00'],
                [SystemAccount::Sales, 'credit', '500.00'],
            ], date: now()->toDateString()),
            $this->owner,
        ));

        $stale = $this->actingForTenant($this->tenant, fn () => $this->engine()->draft(
            $this->batchFor($this->tenant, [
                [SystemAccount::Cash, 'debit', '900.00'],
                [SystemAccount::Sales, 'credit', '900.00'],
            ], date: now()->subDays(ReportService::STALE_AFTER_DAYS + 1)->toDateString()),
            $this->owner,
        ));

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/drafts')
            ->assertOk();

        // Oldest first — the opposite of every other listing here, because a
        // worklist is ordered by what needs attention most.
        $this->assertSame($stale->id, $response->json('data.0.transaction.id'));
        $this->assertTrue($response->json('data.0.is_stale'));
        $this->assertNotNull($response->json('data.0.reason'));

        $this->assertSame($fresh->id, $response->json('data.1.transaction.id'));
        $this->assertFalse($response->json('data.1.is_stale'));
        $this->assertNull($response->json('data.1.reason'));

        $this->assertSame(2, $response->json('meta.totals.count'));
        $this->assertSame(1, $response->json('meta.totals.stale'));
    }

    #[Test]
    public function the_worklist_ignores_the_period_because_a_draft_is_not_an_event(): void
    {
        // The draft from three months ago is precisely the one somebody needs to
        // see, so hiding it because the date picker says "this month" would
        // defeat the purpose.
        $this->actingForTenant($this->tenant, fn () => $this->engine()->draft(
            $this->batchFor($this->tenant, [
                [SystemAccount::Cash, 'debit', '500.00'],
                [SystemAccount::Sales, 'credit', '500.00'],
            ], date: now()->subMonths(3)->toDateString()),
            $this->owner,
        ));

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/drafts?period=this_month')
            ->assertOk()
            ->assertJsonPath('meta.totals.count', 1);
    }

    #[Test]
    public function a_posted_transaction_leaves_the_worklist(): void
    {
        $draft = $this->actingForTenant($this->tenant, fn () => $this->engine()->draft(
            $this->batchFor($this->tenant, [
                [SystemAccount::Cash, 'debit', '500.00'],
                [SystemAccount::Sales, 'credit', '500.00'],
            ]),
            $this->owner,
        ));

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$draft->id}/post")
            ->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/drafts')
            ->assertOk()
            ->assertJsonPath('meta.totals.count', 0);
    }

    /* ---------------------------------------------------------------------
     | The financial year
     | ------------------------------------------------------------------- */

    #[Test]
    public function this_financial_year_follows_the_workshops_own_start_month(): void
    {
        // The April off-by-one, which every hand-rolled report gets wrong in
        // February — computed once, on the model, since M2.2.
        $this->actingForTenant($this->tenant, fn () => $this->tenant->update([
            'financial_year_start_month' => 4,
            'timezone' => 'Asia/Kolkata',
        ]));

        $this->travelTo('2027-02-10 12:00:00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss?period=this_financial_year')
            ->assertOk();

        $this->assertSame('2026-04-01', $response->json('meta.period.from'));
        $this->assertSame('2027-03-31', $response->json('meta.period.to'));
    }

    #[Test]
    public function a_january_financial_year_reports_a_different_window_from_the_same_day(): void
    {
        $this->actingForTenant($this->tenant, fn () => $this->tenant->update([
            'financial_year_start_month' => 1,
            'timezone' => 'Asia/Kolkata',
        ]));

        $this->travelTo('2027-02-10 12:00:00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss?period=this_financial_year')
            ->assertOk();

        $this->assertSame('2027-01-01', $response->json('meta.period.from'));
        $this->assertSame('2027-12-31', $response->json('meta.period.to'));
    }

    #[Test]
    public function the_period_actually_filters_what_is_reported(): void
    {
        $this->actingForTenant($this->tenant, fn () => $this->tenant->update([
            'financial_year_start_month' => 4,
        ]));

        $this->travelTo('2027-02-10 12:00:00');

        // Two sales, one in each financial year.
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '1000.00', '2026-05-01');
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '7000.00', '2025-05-01');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss?period=this_financial_year')
            ->assertOk()
            ->assertJsonPath('meta.totals.revenue', '1000.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss?period=last_financial_year')
            ->assertOk()
            ->assertJsonPath('meta.totals.revenue', '7000.00');

        // And everything, which is what a trial balance is run over.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss?period=all')
            ->assertOk()
            ->assertJsonPath('meta.totals.revenue', '8000.00');
    }

    #[Test]
    public function two_dates_in_the_wrong_order_are_swapped_rather_than_refused(): void
    {
        // Somebody who filled the boxes in the wrong order wants the range
        // between them. Answering "no entries" would look like a workshop with
        // no trade.
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '1000.00', '2026-05-01');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss?from=2026-12-31&to=2026-01-01')
            ->assertOk()
            ->assertJsonPath('meta.period.from', '2026-01-01')
            ->assertJsonPath('meta.period.to', '2026-12-31')
            ->assertJsonPath('meta.totals.revenue', '1000.00');
    }

    #[Test]
    public function a_stale_preset_falls_back_to_everything_rather_than_refusing_to_draw(): void
    {
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '1000.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss?period=last_fortnight_but_one')
            ->assertOk()
            ->assertJsonPath('meta.period.preset', 'all')
            ->assertJsonPath('meta.totals.revenue', '1000.00');
    }

    /* ---------------------------------------------------------------------
     | An empty workshop, and somebody else's numbers
     | ------------------------------------------------------------------- */

    #[Test]
    public function every_report_shows_zero_for_a_workshop_with_no_data(): void
    {
        foreach (['day-book', 'profit-and-loss', 'gst', 'drafts'] as $report) {
            $this->withHeaders($this->authHeader($this->owner))
                ->getJson("/api/v1/reports/{$report}")
                ->assertOk();
        }

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss')
            ->assertOk()
            ->assertJsonPath('meta.totals.revenue', '0.00')
            ->assertJsonPath('meta.totals.net', '0.00')
            ->assertJsonCount(0, 'data.income');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/gst')
            ->assertOk()
            ->assertJsonPath('data.output.tax', '0.00')
            ->assertJsonPath('meta.net_payable', '0.00')
            ->assertJsonPath('meta.reconciliation.agrees', true);
    }

    #[Test]
    public function no_report_shows_another_workshops_numbers(): void
    {
        [, $stranger] = $this->tenantWithUser([
            ['READ', 'LEDGER'], ['READ', 'TRANSACTIONS'],
        ], 'Stranger Role');

        $this->tradeOneBearing();

        $this->actingForTenant($this->tenant, fn () => $this->engine()->draft(
            $this->batchFor($this->tenant, [
                [SystemAccount::Cash, 'debit', '500.00'],
                [SystemAccount::Sales, 'credit', '500.00'],
            ]),
            $this->owner,
        ));

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/reports/day-book')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/reports/profit-and-loss')
            ->assertOk()
            ->assertJsonPath('meta.totals.revenue', '0.00');

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/reports/gst')
            ->assertOk()
            ->assertJsonPath('data.output.taxable', '0.00');

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/reports/drafts')
            ->assertOk()
            ->assertJsonPath('meta.totals.count', 0);
    }

    /* ---------------------------------------------------------------------
     | Authority
     | ------------------------------------------------------------------- */

    #[Test]
    public function a_data_entry_user_sees_their_worklist_and_not_the_workshops_position(): void
    {
        // Capturing events and reading the whole financial position are
        // different authorities — but a worklist only the owner could see would
        // be a worklist nobody acts on.
        $clerk = $this->actingForTenant($this->tenant, fn () => User::factory()
            ->forTenant($this->tenant)
            ->withRole($this->roleWith([
                ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'],
            ], 'Clerk Role'))
            ->create());

        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/reports/drafts')
            ->assertOk();

        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/reports/meta')
            ->assertOk();

        foreach (['day-book', 'profit-and-loss', 'gst'] as $report) {
            $this->withHeaders($this->authHeader($clerk))
                ->getJson("/api/v1/reports/{$report}")
                ->assertForbidden();
        }
    }

    #[Test]
    public function the_period_vocabulary_is_published_rather_than_hard_coded(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/meta')
            ->assertOk();

        $presets = collect($response->json('data.periods'))->pluck('value')->all();

        // "This financial year" has to come from the server, or a client would
        // own the workshop's year-start setting — and be right until somebody
        // changed it.
        $this->assertContains('this_financial_year', $presets);
        $this->assertContains('all', $presets);
        $this->assertSame(ReportService::STALE_AFTER_DAYS, $response->json('data.stale_after_days'));
    }
}
