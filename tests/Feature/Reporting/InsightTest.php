<?php

namespace Tests\Feature\Reporting;

use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reporting\Insights\StockInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * M23 — the insight module.
 *
 * The tests below are mostly about one thing: **these figures agree with the
 * rest of the application.** An insights screen that disagreed with the P&L, the
 * stock summary or the bills list would be worse than no insights screen, because
 * somebody would believe it — and this module sums the document *lines* where
 * most of the application sums the *ledger*, so the agreement is a property that
 * has to be held rather than one that comes for free.
 *
 * The rest is about the judgements that are easy to get wrong in a way that
 * looks right:
 *
 *   * a returned sale is subtracted, and a reversal pair vanishes entirely;
 *   * labour has no cost, so it is out of the margin *percentage* but in the
 *     revenue;
 *   * an ageing measured against terms nobody agreed to is not an ageing;
 *   * what somebody is paid is not visible to somebody who may read the books.
 */
class InsightTest extends TestCase
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
            ['READ', 'ITEMS'], ['READ', 'STOCK'], ['READ', 'STAFF'],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     | ------------------------------------------------------------------- */

    /**
     * The same counterparty every time it is asked for.
     *
     * `firstOrCreate` rather than a factory call, because several tests below
     * trade twice — once in each of two months — and a party name is unique per
     * workshop.
     */
    private function customer(string $name = 'Sharma Motors'): Party
    {
        return $this->partyNamed($name, PartyRole::Customer);
    }

    private function vendor(string $name = 'Kohli Traders'): Party
    {
        return $this->partyNamed($name, PartyRole::Vendor);
    }

    private function partyNamed(string $name, PartyRole $role): Party
    {
        return $this->actingForTenant($this->tenant, fn () => Party::query()->firstOrCreate(
            ['name' => $name],
            ['roles' => [$role->value], 'gstin' => null, 'is_active' => true],
        ));
    }

    /**
     * A posted transaction of one type, read inside the tenant context.
     *
     * Every model in this application carries the tenant scope, which throws
     * rather than returning an empty set when no workshop is established — so a
     * test that reaches for a row outside `actingForTenant` fails with a tenancy
     * error rather than a useful one.
     */
    private function transactionOfType(TransactionType $type): \App\Models\Transaction
    {
        return $this->actingForTenant($this->tenant, fn () => \App\Models\Transaction::query()
            ->where('type', $type->value)
            ->where('status', 'posted')
            ->orderBy('id')
            ->firstOrFail());
    }

    /**
     * Buy ten bearings at ₹100, sell four at ₹250.
     *
     * Revenue ₹1,000, cost ₹400, margin ₹600 — small enough to check by hand,
     * which is the point. Every figure this module reports about that trade is
     * derivable from those two documents.
     *
     * @return array{0: ItemVariant, 1: Party}
     */
    private function tradeOneBearing(?string $date = null, ?ItemVariant $variant = null): array
    {
        $variant ??= $this->variantFor($this->tenant, 'part');
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

    /**
     * Point a settlement at the bills it covers, oldest first.
     *
     * A deliberate act in this application rather than something a receipt does
     * on its own — see SettlementService, which will not guess which invoice
     * somebody meant. Several tests below turn on the difference.
     */
    private function allocate(\App\Models\Transaction $settlement): void
    {
        $this->actingForTenant($this->tenant, fn () => app(
            \App\Services\Accounting\SettlementService::class
        )->allocate($settlement));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function insight(string $panel, array $query = [], ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeader($as ?? $this->owner))
            ->getJson('/api/v1/insights/'.$panel.'?'.http_build_query($query + ['period' => 'all']));
    }

    /* ---------------------------------------------------------------------
     | It agrees with everything else
     | ------------------------------------------------------------------- */

    #[Test]
    public function the_overview_reports_the_same_revenue_the_profit_and_loss_does(): void
    {
        $this->tradeOneBearing();

        $overview = $this->insight('overview')->assertOk();

        // The reconciliation is the module's own claim that it agrees. Where
        // every rupee of income arrived through a bill, it must.
        $this->assertTrue($overview->json('data.reconciliation.agrees'));
        $this->assertSame('0.00', $overview->json('data.reconciliation.difference'));

        $statement = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/reports/profit-and-loss?period=all')
            ->assertOk();

        $this->assertSame(
            $statement->json('meta.totals.revenue'),
            $overview->json('data.reconciliation.document_revenue'),
        );
    }

    #[Test]
    public function a_journal_straight_to_sales_is_reported_as_a_difference_and_never_repaired(): void
    {
        $this->tradeOneBearing();

        // The correction mechanism M4 deliberately allows: income with no bill
        // line behind it. This module reads the documents, so it cannot see it —
        // and it says so rather than quietly reconciling itself.
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '5000.00');

        $overview = $this->insight('overview')->assertOk();

        $this->assertFalse($overview->json('data.reconciliation.agrees'));
        $this->assertSame('5000.00', $overview->json('data.reconciliation.difference'));

        // Reported, not absorbed: the document revenue is unchanged.
        $this->assertSame('1000.00', $overview->json('data.reconciliation.document_revenue'));
    }

    #[Test]
    public function the_stock_panel_reports_the_same_value_the_stock_summary_does(): void
    {
        $this->tradeOneBearing();

        $panel = $this->insight('stock')->assertOk();

        $summary = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/stock/summary')
            ->assertOk();

        $this->assertSame(
            $summary->json('data.value'),
            $panel->json('data.position.value'),
        );

        // Six bearings left at ₹100 — the weighted average the stock ledger
        // owns, not a second arithmetic computed here.
        $this->assertSame('600.00', $panel->json('data.position.value'));
    }

    /* ---------------------------------------------------------------------
     | Margin
     | ------------------------------------------------------------------- */

    #[Test]
    public function margin_is_revenue_less_the_cost_of_the_stock_that_moved(): void
    {
        $this->tradeOneBearing();

        $sales = $this->insight('sales')->assertOk();

        $this->assertSame('1000.00', $sales->json('data.totals.revenue'));
        $this->assertSame('400.00', $sales->json('data.totals.cost'));
        $this->assertSame('600.00', $sales->json('data.totals.margin'));
        $this->assertSame('60.00', $sales->json('data.totals.margin_percent'));
    }

    #[Test]
    public function labour_is_revenue_but_is_kept_out_of_the_margin_percentage(): void
    {
        [$variant, $customer] = $this->tradeOneBearing();
        $labour = $this->serviceVariantFor($this->tenant, '500.00');

        $this->actingForTenant($this->tenant, fn () => $this->engine()->postComposed(
            TransactionType::Sale,
            [
                'date' => now()->toDateString(),
                'party_id' => $customer->id,
                'items' => [[
                    'item_id' => $labour->item_id,
                    'variant_id' => $labour->id,
                    'quantity' => '1',
                    'unit_price' => '500.00',
                ]],
                'payments' => [],
            ],
            $this->owner,
        ));

        $sales = $this->insight('sales')->assertOk();

        // The labour is revenue — it is what the workshop earned.
        $this->assertSame('1500.00', $sales->json('data.totals.revenue'));

        /*
        | But the percentage is still 60%, not 73%.
        |
        | An hour is produced at the moment it is sold, so a labour line has no
        | cost of goods — reporting the whole of it as margin would flatter the
        | figure on every screen it appears on. The margin *amount* includes it
        | nowhere and the *denominator* excludes it: 600 over the 1,000 of goods
        | revenue, not over 1,500.
        */
        $this->assertSame('1000.00', $sales->json('data.totals.costed_revenue'));
        $this->assertSame('600.00', $sales->json('data.totals.margin'));
        $this->assertSame('60.00', $sales->json('data.totals.margin_percent'));

        // And the mix says which business the money came from — the split a
        // rewinding shop cares about and the ledger cannot make.
        $this->assertSame('goods', $sales->json('data.mix.0.key'));
        $this->assertSame('1000.00', $sales->json('data.mix.0.revenue'));
        $this->assertSame('500.00', $sales->json('data.mix.1.revenue'));
    }

    #[Test]
    public function a_line_sold_below_cost_is_listed_worst_first(): void
    {
        [$variant, $customer] = $this->tradeOneBearing();

        // Two more out at ₹40, against a weighted average of ₹100.
        $this->actingForTenant($this->tenant, fn () => $this->engine()->postComposed(
            TransactionType::Sale,
            [
                'date' => now()->toDateString(),
                'party_id' => $customer->id,
                'items' => [[
                    'item_id' => $variant->item_id,
                    'variant_id' => $variant->id,
                    'quantity' => '2',
                    'unit_price' => '40.00',
                ]],
                'payments' => [],
            ],
            $this->owner,
        ));

        $sales = $this->insight('sales')->assertOk();

        $this->assertCount(1, $sales->json('data.below_cost'));
        $this->assertSame('80.00', $sales->json('data.below_cost.0.revenue'));
        $this->assertSame('200.00', $sales->json('data.below_cost.0.cost'));
        $this->assertSame('120.00', $sales->json('data.below_cost.0.shortfall'));

        // And it reaches the exception feed, which is the part somebody acts on.
        $keys = array_column($this->insight('overview')->json('data.attention'), 'key');
        $this->assertContains('below_cost', $keys);
    }

    /* ---------------------------------------------------------------------
     | Returns, and reversals
     | ------------------------------------------------------------------- */

    #[Test]
    public function a_returned_sale_is_subtracted_from_revenue_and_reported_beside_it(): void
    {
        [$variant, $customer] = $this->tradeOneBearing();

        $sale = $this->transactionOfType(TransactionType::Sale);
        $line = $this->actingForTenant($this->tenant, fn () => $sale->lines()->firstOrFail());

        $this->withHeaders($this->authHeader($this->owner))
            // A line number and a quantity, against the bill the URL names —
            // everything else is read off the original.
            ->postJson('/api/v1/transactions/'.$sale->id.'/return', [
                'date' => now()->toDateString(),
                'lines' => [['line_no' => $line->line_no, 'quantity' => '1']],
            ])
            ->assertSuccessful();

        $sales = $this->insight('sales')->assertOk();

        // Net revenue is what the workshop actually earned…
        $this->assertSame('750.00', $sales->json('data.totals.revenue'));

        // …but gross and returned are both reported beside it, so a month that
        // looks quiet because half of it came back says which of the two
        // happened.
        $this->assertSame('1000.00', $sales->json('data.totals.gross_revenue'));
        $this->assertSame('250.00', $sales->json('data.totals.returns'));
        $this->assertSame(1, $sales->json('data.totals.returns_count'));
    }

    #[Test]
    public function a_reversed_sale_and_its_reversal_both_disappear(): void
    {
        [$variant, $customer] = $this->tradeOneBearing();

        $sale = $this->transactionOfType(TransactionType::Sale);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/'.$sale->id.'/reverse', [
                'date' => now()->toDateString(),
                'reason' => 'Entered against the wrong customer.',
            ])
            ->assertSuccessful();

        $sales = $this->insight('sales')->assertOk();

        /*
        | Both halves of the pair are gone, not netted.
        |
        | Excluding only the reversed document would leave the cancelling one to
        | be counted as a fresh sale; excluding only the reversal would leave the
        | original. The document count is what makes the difference visible —
        | netting to zero revenue while still reporting two sales would be the
        | bug this test exists to catch.
        */
        $this->assertSame('0.00', $sales->json('data.totals.revenue'));
        $this->assertSame(0, $sales->json('data.totals.documents'));
    }

    /* ---------------------------------------------------------------------
     | The ageing
     | ------------------------------------------------------------------- */

    #[Test]
    public function an_unpaid_invoice_is_aged_from_its_date_when_the_workshop_has_set_no_terms(): void
    {
        // Nullable on purpose: a counter trade settles on the spot and has no
        // terms. Set explicitly here because the factory gives a workshop 30
        // days, and this test is about the workshop that has none.
        $this->tenant->forceFill(['payment_due_days' => null])->save();

        $this->tradeOneBearing(now()->subDays(100)->toDateString());

        $credit = $this->insight('credit')->assertOk();

        // Said plainly, because an ageing measured from the invoice date means
        // something different from one measured against agreed terms.
        $this->assertNull($credit->json('data.terms.payment_due_days'));
        $this->assertSame('invoice_date', $credit->json('data.terms.basis'));

        // The document total, GST included — what is owed is the invoice, not
        // the taxable value the margin is taken against.
        $this->assertSame('1180.00', $credit->json('data.receivable.total'));
        $this->assertSame(1, $credit->json('data.receivable.bills'));

        // 100 days from the invoice date, so the last bucket.
        $buckets = collect($credit->json('data.receivable.buckets'))->keyBy('label');
        $this->assertSame('1180.00', $buckets['Over 90 days']['amount']);
        $this->assertSame(0, $buckets['0–30 days']['count']);
    }

    #[Test]
    public function payment_terms_move_every_bucket_with_them(): void
    {
        $this->tradeOneBearing(now()->subDays(100)->toDateString());

        // 30-day terms, as the factory sets: 100 days old is 70 days past due.
        $this->assertSame(30, $this->tenant->fresh()->payment_due_days);

        $buckets = collect($this->insight('credit')->assertOk()->json('data.receivable.buckets'))
            ->keyBy('label');

        $this->assertSame('1180.00', $buckets['61–90 days']['amount']);
        $this->assertSame('0.00', $buckets['Over 90 days']['amount']);

        // Widen the terms and the same invoice is not late at all — which is why
        // treating "no terms" as "due immediately" would be so misleading.
        $this->tenant->forceFill(['payment_due_days' => 120])->save();

        $widened = collect($this->insight('credit')->assertOk()->json('data.receivable.buckets'))
            ->keyBy('label');

        $this->assertSame('due_date', $this->insight('credit')->json('data.terms.basis'));
        $this->assertSame('1180.00', $widened['Not yet due']['amount']);
        $this->assertSame('0.00', $widened['61–90 days']['amount']);
    }

    #[Test]
    public function a_settled_invoice_leaves_the_ageing_entirely(): void
    {
        [, $customer] = $this->tradeOneBearing();

        // The whole document, GST included — what is owed is the invoice total.
        $receipt = $this->receiveFrom($this->tenant, $customer, [['cash', '1180.00']]);

        /*
        | Allocated, and that is the subject of the test rather than an
        | incidental step. The ageing counts **open documents**, so a bill leaves
        | it when something is pointed at it — the same rule the bills list's
        | `outstanding` filter follows, from the same SQL expression.
        */
        $this->allocate($receipt);

        $credit = $this->insight('credit')->assertOk();

        $this->assertSame('0.00', $credit->json('data.receivable.total'));
        $this->assertSame(0, $credit->json('data.receivable.bills'));
        $this->assertSame('0.00', $credit->json('data.unallocated.amount'));
    }

    #[Test]
    public function a_receipt_nobody_pointed_at_an_invoice_is_reported_rather_than_netted_away(): void
    {
        [, $customer] = $this->tradeOneBearing();

        // Banked, but never told which invoice it settles.
        $this->receiveFrom($this->tenant, $customer, [['cash', '1180.00']]);

        $credit = $this->insight('credit')->assertOk();

        /*
        | Both figures are true about different questions, and the panel says so.
        |
        | The ageing counts open documents, so the invoice is still there; the
        | customer's balance counts the ledger, so it is already nil. Netting one
        | into the other would make the total right and every row wrong — and
        | clearing the invoice automatically would hide work somebody still has
        | to do, because nothing here may guess which invoice a cheque was for.
        */
        $this->assertSame('1180.00', $credit->json('data.receivable.total'));
        $this->assertSame('1180.00', $credit->json('data.unallocated.amount'));
        $this->assertSame(1, $credit->json('data.unallocated.receipts'));
    }

    #[Test]
    public function a_customer_who_has_paid_ahead_is_credit_held_and_not_a_negative_debt(): void
    {
        [, $customer] = $this->tradeOneBearing();

        $this->allocate($this->receiveFrom($this->tenant, $customer, [['cash', '1680.00']]));

        $credit = $this->insight('credit')->assertOk();

        // Not a bucket, and not a minus inside the total. An over-payment is a
        // balance with no invoice behind it, and showing it in the colour that
        // means "chase this" would send somebody after money the workshop is
        // holding.
        $this->assertSame('0.00', $credit->json('data.receivable.total'));
        $this->assertSame('500.00', $credit->json('data.credit_held.amount'));
        $this->assertSame(1, $credit->json('data.credit_held.parties'));
    }

    /* ---------------------------------------------------------------------
     | Stock
     | ------------------------------------------------------------------- */

    #[Test]
    public function stock_that_has_not_been_issued_for_a_season_is_reported_as_idle(): void
    {
        // Received four months ago and never sold.
        $variant = $this->variantFor($this->tenant, 'part');
        $this->receiveStock($this->tenant, $variant, '5', '80.00', now()->subDays(120)->toDateString());

        $panel = $this->insight('stock')->assertOk();

        $this->assertSame(StockInsights::DEAD_AFTER_DAYS, $panel->json('data.dead.threshold_days'));
        $this->assertSame(1, $panel->json('data.dead.variants'));
        $this->assertSame('400.00', $panel->json('data.dead.value'));

        // "Never sold" and "not sold since April" are different problems — one
        // is a buying mistake, the other a part that has gone out of use.
        $this->assertTrue($panel->json('data.dead.rows.0.never_issued'));
    }

    #[Test]
    public function a_write_off_is_surfaced_because_the_profit_and_loss_cannot_show_it(): void
    {
        [$variant] = $this->tradeOneBearing();

        // Counted two short. It posts to COGS, so it is indistinguishable from a
        // sale on the statement — this panel is the only place it is visible.
        $this->adjustStock($this->tenant, [[$variant, '-2']], null, 'Stock-take');

        $panel = $this->insight('stock')->assertOk();

        $this->assertSame('200.00', $panel->json('data.shrinkage.written_off'));
        $this->assertSame('0.00', $panel->json('data.shrinkage.found'));
        $this->assertSame(1, $panel->json('data.shrinkage.counts'));

        $keys = array_column($this->insight('overview')->json('data.attention'), 'key');
        $this->assertContains('shrinkage', $keys);
    }

    /* ---------------------------------------------------------------------
     | Comparison
     | ------------------------------------------------------------------- */

    #[Test]
    public function a_headline_carries_no_delta_when_there_is_nothing_to_compare_against(): void
    {
        $this->tradeOneBearing();

        // All time has no window before it. A delta invented for it would be a
        // percentage that means nothing, so there is none.
        $overview = $this->insight('overview')->assertOk();

        $this->assertNull($overview->json('data.compared_with'));

        foreach ($overview->json('data.headlines') as $tile) {
            $this->assertNull($tile['delta'], "The {$tile['key']} tile invented a comparison.");
        }
    }

    #[Test]
    public function a_month_is_compared_against_the_window_of_equal_length_before_it(): void
    {
        $lastMonth = now()->subMonthNoOverflow();

        $this->tradeOneBearing($lastMonth->copy()->startOfMonth()->addDay()->toDateString());
        $this->tradeOneBearing(now()->startOfMonth()->addDay()->toDateString());

        $overview = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/insights/overview?period=this_month')
            ->assertOk();

        $this->assertNotNull($overview->json('data.compared_with'));

        $revenue = collect($overview->json('data.headlines'))->firstWhere('key', 'revenue');

        $this->assertSame('1000.00', $revenue['value']);
        // Identical months, so the change is nil — and "flat" is a direction of
        // its own rather than a missing delta.
        $this->assertSame('flat', $revenue['delta']['direction']);
        $this->assertSame('1000.00', $revenue['delta']['from']);
    }

    /* ---------------------------------------------------------------------
     | What is withheld, and from whom
     | ------------------------------------------------------------------- */

    #[Test]
    public function reading_the_books_does_not_grant_sight_of_what_people_are_paid(): void
    {
        $bookkeeper = $this->userWithGrants([
            ['READ', 'LEDGER'], ['READ', 'TRANSACTIONS'],
        ], 'Bookkeeper');

        $this->actingForTenant($this->tenant, fn () => $bookkeeper
            ->forceFill(['tenant_id' => $this->tenant->id])->save());

        // STAFF is the one grant withheld for privacy rather than authority.
        $this->insight('people', [], $bookkeeper)->assertForbidden();

        // And the overview omits the wage tile entirely — absent, not blanked. A
        // tile reading "—" tells somebody there is a number there.
        $keys = array_column($this->insight('overview', [], $bookkeeper)->json('data.headlines'), 'key');

        $this->assertNotContains('staff_cost', $keys);
        $this->assertContains('revenue', $keys);

        // The owner, who holds it, sees the tile.
        $ownerKeys = array_column($this->insight('overview')->json('data.headlines'), 'key');
        $this->assertContains('staff_cost', $ownerKeys);
    }

    #[Test]
    public function every_panel_is_refused_without_the_ledger_grant(): void
    {
        $clerk = $this->userWithGrants([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'],
        ], 'Counter clerk');

        $this->actingForTenant($this->tenant, fn () => $clerk
            ->forceFill(['tenant_id' => $this->tenant->id])->save());

        foreach (['overview', 'sales', 'purchase', 'stock', 'credit', 'people'] as $panel) {
            $this->insight($panel, [], $clerk)->assertForbidden();
        }

        // Except the meta, which publishes the period presets and says nothing
        // about the workshop's money — the same line reports/meta draws.
        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/insights/meta')
            ->assertOk()
            ->assertJsonPath('data.may_read_staff', false);
    }

    /* ---------------------------------------------------------------------
     | Nothing at all
     | ------------------------------------------------------------------- */

    #[Test]
    public function a_workshop_that_has_posted_nothing_gets_zeroes_rather_than_an_error(): void
    {
        $this->insight('overview')->assertOk()->assertJsonPath('data.reconciliation.agrees', true);
        $this->insight('sales')->assertOk()->assertJsonPath('data.totals.revenue', '0.00');
        $this->insight('credit')->assertOk()->assertJsonPath('data.receivable.total', '0.00');

        // A turnover ratio is null rather than zero on a workshop holding no
        // stock: "0.00" would read as "your stock is not moving" when the truth
        // is that there is none.
        $this->insight('stock')->assertOk()->assertJsonPath('data.turnover.ratio', null);

        // And the module says so once, at the top, rather than eight times
        // inside panels that are each correctly empty.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/insights/meta')
            ->assertOk()
            ->assertJsonPath('data.has_data', false);
    }

    #[Test]
    public function one_workshop_never_sees_another_workshops_figures(): void
    {
        $this->tradeOneBearing();

        [$other, $stranger] = $this->tenantWithUser([
            ['READ', 'LEDGER'], ['READ', 'TRANSACTIONS'], ['READ', 'STOCK'],
        ], 'Other Owner');

        $this->assertNotSame($this->tenant->id, $other->id);

        // Every query in this module goes through an Eloquent model so the
        // tenant scope binds — a raw DB::table() anywhere in it would read the
        // whole platform, which is why this test exists at all.
        $this->insight('sales', [], $stranger)->assertOk()->assertJsonPath('data.totals.revenue', '0.00');
        $this->insight('stock', [], $stranger)->assertOk()->assertJsonPath('data.position.value', '0.00');
        $this->insight('credit', [], $stranger)->assertOk()->assertJsonPath('data.receivable.total', '0.00');
    }
}
