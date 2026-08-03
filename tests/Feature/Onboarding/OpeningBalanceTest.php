<?php

namespace Tests\Feature\Onboarding;

use App\Enums\ItemType;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\BooksClosedException;
use App\Exceptions\Accounting\TransactionImmutableException;
use App\Exceptions\Onboarding\OpeningBalanceException;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\OpeningImport;
use App\Models\Party;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Onboarding\OpeningBalanceService;
use App\Services\Onboarding\OpeningCsvParser;
use App\Services\Onboarding\OpeningPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * M11 — getting a running workshop's existing position into the books.
 *
 * The roadmap's four checks are each a test here, and the third one is the
 * module's whole reason for having a per-target guard rather than only a file
 * fingerprint:
 *
 *   * an import produces a reconciling trial balance;
 *   * a deliberate mismatch surfaces as an OBE residual, not a silent error;
 *   * re-importing the same file does not double the balances;
 *   * fuzzy matching does not create duplicate variants.
 */
class OpeningBalanceTest extends TestCase
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
            ['READ', 'LEDGER'], ['READ', 'TRANSACTIONS'],
            ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'WORKSPACE'],
            ['READ', 'ITEMS'], ['WRITE', 'ITEMS'],
            ['READ', 'PARTIES'], ['WRITE', 'PARTIES'],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    private function service(): OpeningBalanceService
    {
        return app(OpeningBalanceService::class);
    }

    /**
     * Import a CSV as the workshop, returning the receipt.
     */
    private function import(string $csv, ?string $date = null): OpeningImport
    {
        return $this->actingForTenant($this->tenant, fn () => $this->service()->import(
            app(OpeningCsvParser::class)->parse($csv),
            $date,
            'opening.csv',
            $this->owner,
        ));
    }

    private function plan(string $csv): OpeningPlan
    {
        return $this->actingForTenant(
            $this->tenant,
            fn () => $this->service()->planCsv($csv, null, 'opening.csv'),
        );
    }

    /* ---------------------------------------------------------------------
     | The trial balance
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_import_produces_a_reconciling_trial_balance(): void
    {
        $this->import(<<<'CSV'
        kind,name,variant,type,quantity,unit_cost,amount,account,gstin
        stock,Ball Bearing,6204,part,10,120.00,,,
        stock,Copper Wire,22 SWG,bulk_material,40,720.00,,,
        receivable,Sharma Motors,,,,,15000.00,,
        payable,Kohli Traders,,,,,32000.00,,
        balance,,,,,,40000.00,Cash in Hand,
        CSV);

        $this->assertBooksBalance($this->tenant, 'after a go-live import');
        $this->assertStockAgreesWithInventoryAccount($this->tenant, 'after a go-live import');
    }

    #[Test]
    public function every_side_of_the_declaration_lands_on_the_account_it_names(): void
    {
        $this->import(<<<'CSV'
        kind,name,variant,type,quantity,unit_cost,amount,account
        stock,Ball Bearing,6204,part,10,120.00,,
        receivable,Sharma Motors,,,,,15000.00,
        payable,Kohli Traders,,,,,32000.00,
        balance,,,,,,40000.00,Cash in Hand
        CSV);

        // 10 × ₹120 on the shelf, and the same figure in the Inventory account.
        $this->assertSame('1200.00', $this->balanceOf($this->tenant, SystemAccount::Inventory));
        $this->assertSame('15000.00', $this->balanceOf($this->tenant, SystemAccount::Receivables));
        $this->assertSame('32000.00', $this->balanceOf($this->tenant, SystemAccount::Payables));
        $this->assertSame('40000.00', $this->balanceOf($this->tenant, SystemAccount::Cash));

        // Assets 1200 + 15000 + 40000 = 56,200, less 32,000 owed: the owner's
        // stake at go-live, and every rupee of it in Opening Balance Equity.
        $this->assertSame('24200.00', $this->balanceOf($this->tenant, SystemAccount::OpeningBalanceEquity));
    }

    #[Test]
    public function opening_stock_is_not_a_purchase(): void
    {
        // No GST is claimed and nothing is owed to anybody: the workshop bought
        // this stock years ago and claimed the tax then. Routing it through a
        // purchase would report the first month as an enormous acquisition.
        $this->import(<<<'CSV'
        kind,name,variant,type,quantity,unit_cost
        stock,Ball Bearing,6204,part,10,120.00
        CSV);

        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::GstInput));
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Payables));

        $movement = $this->actingForTenant(
            $this->tenant,
            fn () => StockMovement::query()->firstOrFail(),
        );

        // Typed `opening`, not `in`. A stock report that could not tell them
        // apart would show a go-live as a month of buying.
        $this->assertSame('opening', $movement->type->value);
    }

    /* ---------------------------------------------------------------------
     | The residual
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_forgotten_declaration_surfaces_as_an_owners_stake_that_is_short(): void
    {
        // The workshop declares its debts but forgets the ₹40,000 in the till.
        // The books still reconcile — every opening line is posted against OBE,
        // so they always do — and the mistake shows as a stake that is negative
        // when the owner knows it is not.
        $this->import(<<<'CSV'
        kind,name,amount
        payable,Kohli Traders,32000.00
        CSV);

        $this->assertBooksBalance($this->tenant, 'even when a declaration was left out');

        $position = $this->actingForTenant($this->tenant, fn () => $this->service()->position());

        $this->assertTrue($position['trial_balance']['is_balanced']);
        $this->assertSame('-32000.00', $position['owners_stake']);
    }

    #[Test]
    public function the_preview_reports_the_owners_stake_before_anything_is_posted(): void
    {
        $plan = $this->plan(<<<'CSV'
        kind,name,variant,type,quantity,unit_cost,amount,account
        stock,Ball Bearing,6204,part,10,120.00,,
        payable,Kohli Traders,,,,,32000.00,
        CSV);

        $summary = $plan->summary();

        $this->assertSame('1200.00', $summary['assets']);
        $this->assertSame('32000.00', $summary['liabilities']);
        $this->assertSame('-30800.00', $summary['owners_stake']);

        // And nothing reached the books.
        $this->assertSame(0, $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::query()->count(),
        ));
    }

    /* ---------------------------------------------------------------------
     | Re-importing
     |-------------------------------------------------------------------- */

    #[Test]
    public function re_importing_the_same_file_is_refused_by_its_fingerprint(): void
    {
        $csv = <<<'CSV'
        kind,name,variant,type,quantity,unit_cost
        stock,Ball Bearing,6204,part,10,120.00
        CSV;

        $this->import($csv);

        $this->expectException(OpeningBalanceException::class);

        $this->import($csv);
    }

    #[Test]
    public function an_edited_file_cannot_double_a_balance_either(): void
    {
        // The fingerprint is defeated the moment a single figure changes, which
        // is why the guard that actually holds is per target rather than per
        // file: the variant already carries opening stock, so the row is skipped
        // whatever the rest of the file says.
        $this->import(<<<'CSV'
        kind,name,variant,type,quantity,unit_cost
        stock,Ball Bearing,6204,part,10,120.00
        CSV);

        $before = $this->balanceOf($this->tenant, SystemAccount::Inventory);

        $import = $this->import(<<<'CSV'
        kind,name,variant,type,quantity,unit_cost
        stock,Ball Bearing,6204,part,10,120.00
        stock,Ball Bearing,6204,part,99,999.00
        CSV);

        $this->assertSame($before, $this->balanceOf($this->tenant, SystemAccount::Inventory));
        $this->assertSame(0, $import->imported_count);
        $this->assertSame(2, $import->skipped_count);
        $this->assertBooksBalance($this->tenant, 'after a partially-repeated import');
    }

    #[Test]
    public function a_party_already_opened_is_skipped_rather_than_declared_twice(): void
    {
        $this->import(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,15000.00
        CSV);

        // A corrected figure, so the fingerprint does not catch it — the party
        // guard has to, or the workshop would show ₹30,500 owed by a customer
        // who owes ₹15,000.
        $import = $this->import(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,15500.00
        CSV);

        $this->assertSame('15000.00', $this->balanceOf($this->tenant, SystemAccount::Receivables));
        $this->assertSame(1, $import->skipped_count);
        $this->assertSame(0, $import->imported_count);
    }

    #[Test]
    public function a_re_sorted_file_hashes_the_same_and_is_still_refused(): void
    {
        // Somebody who sorted their spreadsheet by supplier and uploaded it
        // again has not changed a single figure.
        $this->import(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,15000.00
        payable,Kohli Traders,32000.00
        CSV);

        $this->expectException(OpeningBalanceException::class);

        $this->import(<<<'CSV'
        kind,name,amount
        payable,Kohli Traders,32000.00
        receivable,Sharma Motors,15000.00
        CSV);
    }

    /* ---------------------------------------------------------------------
     | Matching
     |-------------------------------------------------------------------- */

    #[Test]
    public function fuzzy_matching_does_not_create_a_duplicate_variant(): void
    {
        $variant = $this->variantFor($this->tenant, ItemType::Part);

        $this->actingForTenant($this->tenant, function () use ($variant) {
            $variant->item->update(['name' => 'Ball Bearing']);
            $variant->update(['label' => '6204']);
        });

        $this->import(<<<'CSV'
        kind,name,variant,quantity,unit_cost
        stock,ball bearings,6204,10,120.00
        CSV);

        $this->actingForTenant($this->tenant, function () {
            $this->assertSame(1, Item::query()->count(), 'A near-identical name created a second item.');
            $this->assertSame(1, ItemVariant::query()->count(), 'A matched variant was duplicated.');
        });
    }

    #[Test]
    public function two_rows_naming_one_new_item_create_it_once(): void
    {
        $this->import(<<<'CSV'
        kind,name,variant,type,quantity,unit_cost
        stock,Ball Bearing,6204,part,10,120.00
        stock,Ball Bearing,6205,part,4,150.00
        CSV);

        $this->actingForTenant($this->tenant, function () {
            $this->assertSame(1, Item::query()->count());
            $this->assertSame(2, ItemVariant::query()->count());
        });
    }

    #[Test]
    public function a_name_that_is_merely_similar_is_not_matched(): void
    {
        // "Verma Motors" is a different business in the next street. A matcher
        // with no floor would attach ₹15,000 of somebody else's debt to it.
        $this->actingForTenant(
            $this->tenant,
            fn () => Party::factory()->create(['name' => 'Verma Motors', 'roles' => ['customer']]),
        );

        $this->import(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,15000.00
        CSV);

        $this->actingForTenant($this->tenant, function () {
            $this->assertSame(2, Party::query()->count());
            $this->assertTrue(Party::where('name', 'Sharma Motors')->exists());
        });
    }

    #[Test]
    public function a_legal_suffix_does_not_split_one_party_into_two(): void
    {
        $this->actingForTenant(
            $this->tenant,
            fn () => Party::factory()->create(['name' => 'Sharma Motors', 'roles' => ['customer']]),
        );

        $this->import(<<<'CSV'
        kind,name,amount
        receivable,"M/s Sharma Motors Pvt. Ltd.",15000.00
        CSV);

        $this->actingForTenant(
            $this->tenant,
            fn () => $this->assertSame(1, Party::query()->count(), 'One counterparty became two records.'),
        );
    }

    #[Test]
    public function declaring_a_payable_makes_an_existing_customer_a_vendor_too(): void
    {
        // M5's multi-value roles, exercised at the one moment a workshop
        // describes its whole trading history at once.
        $party = $this->actingForTenant(
            $this->tenant,
            fn () => Party::factory()->create(['name' => 'Sharma Motors', 'roles' => ['customer']]),
        );

        $this->import(<<<'CSV'
        kind,name,amount
        payable,Sharma Motors,32000.00
        CSV);

        $this->assertSame(
            ['customer', 'vendor'],
            $this->actingForTenant($this->tenant, fn () => $party->fresh()->roles),
        );
    }

    #[Test]
    public function a_counterparty_owed_and_owing_gets_one_transaction_with_both(): void
    {
        $this->import(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,40000.00
        payable,Sharma Motors,12000.00
        CSV);

        $this->actingForTenant($this->tenant, function () {
            $this->assertSame(1, Transaction::query()->count(), 'One party, one opening document.');
        });

        $party = $this->actingForTenant($this->tenant, fn () => Party::query()->firstOrFail());

        // Reported as two figures and never netted — M5's decision, and the
        // reason it matters here is that the two are settled on different terms.
        $this->assertSame(
            ['receivable' => '40000.00', 'payable' => '12000.00', 'net' => '28000.00'],
            $this->positionOf($this->tenant, $party),
        );
    }

    /* ---------------------------------------------------------------------
     | What is refused
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_new_item_without_a_type_is_an_error_rather_than_a_guess(): void
    {
        $plan = $this->plan(<<<'CSV'
        kind,name,variant,quantity,unit_cost
        stock,Ball Bearing,6204,10,120.00
        CSV);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('what kind of thing it is', $plan->errors()[0]->reason);
    }

    #[Test]
    public function a_motor_declared_without_its_full_specification_is_refused(): void
    {
        // A motor whose HP was never captured is unidentifiable by anybody
        // afterwards, and `type` and `base_uom` are permanent — so this is a
        // mistake that cannot be corrected later.
        $plan = $this->plan(<<<'CSV'
        kind,name,variant,type,quantity,unit_cost
        stock,Induction Motor,5 HP,motor,2,8200.00
        CSV);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('gives 1 of 3', $plan->errors()[0]->reason);
    }

    #[Test]
    public function a_motor_declared_in_the_form_the_app_prints_round_trips(): void
    {
        $this->import(<<<'CSV'
        kind,name,variant,type,quantity,unit_cost
        stock,Induction Motor,5 HP / 3 ph / 1440 RPM,motor,2,8200.00
        CSV);

        $variant = $this->actingForTenant(
            $this->tenant,
            fn () => ItemVariant::with('item')->firstOrFail(),
        );

        // The suffixes the app prints are taken back off, or the label would
        // read "5 HP HP". assertEquals rather than assertSame: MySQL stores a
        // JSON object with its keys re-sorted by length, so the order the
        // service wrote them in does not survive a round trip and is not what
        // this test is about.
        $this->assertEquals(['hp' => '5', 'phase' => '3', 'rpm' => '1440'], $variant->attributeBag());
        $this->assertSame('16400.00', $this->balanceOf($this->tenant, SystemAccount::Inventory));
    }

    #[Test]
    public function an_unknown_account_is_an_error_and_never_invented(): void
    {
        // The chart of accounts is structural. An account created from a
        // spreadsheet cell would land in whichever code band its name suggested.
        $plan = $this->plan(<<<'CSV'
        kind,amount,account
        balance,5000.00,Petty Cash Tin
        CSV);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('Add it on the Accounting screen first', $plan->errors()[0]->reason);
    }

    #[Test]
    public function the_receivables_control_account_cannot_be_opened_as_a_lump_sum(): void
    {
        $plan = $this->plan(<<<'CSV'
        kind,amount,account
        balance,90000.00,Sundry Debtors (Receivables)
        CSV);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('one "receivable" row per party', $plan->errors()[0]->reason);
    }

    #[Test]
    public function inventory_cannot_be_declared_as_a_figure(): void
    {
        $plan = $this->plan(<<<'CSV'
        kind,amount,account
        balance,412000.00,Inventory
        CSV);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('listing what is on the shelf', $plan->errors()[0]->reason);
    }

    #[Test]
    public function opening_balance_equity_cannot_be_declared_at_all(): void
    {
        $plan = $this->plan(<<<'CSV'
        kind,amount,account
        balance,100000.00,Opening Balance Equity
        CSV);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('worked out, not typed', $plan->errors()[0]->reason);
    }

    #[Test]
    public function stock_with_no_value_is_refused_rather_than_carried_at_nothing(): void
    {
        // Opening stock valued at zero reports a margin of 100% on the first one
        // sold, which is a number nobody would ever question.
        $plan = $this->plan(<<<'CSV'
        kind,name,variant,type,quantity
        stock,Ball Bearing,6204,part,10
        CSV);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('margin of 100%', $plan->errors()[0]->reason);
    }

    #[Test]
    public function a_service_can_never_have_been_on_the_shelf(): void
    {
        $service = $this->serviceVariantFor($this->tenant);

        $this->actingForTenant(
            $this->tenant,
            fn () => $service->item->update(['name' => 'Rewinding Labour'])
        );

        $plan = $this->plan(<<<'CSV'
        kind,name,variant,quantity,unit_cost
        stock,Rewinding Labour,standard,10,800.00
        CSV);

        $this->assertTrue($plan->hasErrors());
        $this->assertStringContainsString('cannot be held in stock', $plan->errors()[0]->reason);
    }

    #[Test]
    public function nothing_posts_when_any_row_cannot_be_resolved(): void
    {
        // Refused whole, deliberately: the only way to find out what a
        // half-imported go-live landed is to reconcile the lot by hand.
        try {
            $this->import(<<<'CSV'
            kind,name,variant,type,quantity,unit_cost,amount,account
            stock,Ball Bearing,6204,part,10,120.00,,
            balance,,,,,,5000.00,Petty Cash Tin
            CSV);

            $this->fail('An unresolvable row should have refused the whole import.');
        } catch (OpeningBalanceException $exception) {
            $this->assertSame('OPENING_PLAN_HAS_ERRORS', $exception->errorCode());
        }

        $this->actingForTenant($this->tenant, function () {
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, Item::query()->count(), 'A refused import left a catalogue record behind.');
        });
    }

    /* ---------------------------------------------------------------------
     | Dates
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_opening_balance_is_dated_at_go_live_by_default(): void
    {
        $this->actingForTenant(
            $this->tenant,
            fn () => $this->tenant->update(['books_start_date' => '2026-04-01'])
        );

        $this->import(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,15000.00
        CSV);

        $transaction = $this->actingForTenant($this->tenant, fn () => Transaction::query()->firstOrFail());

        $this->assertSame('2026-04-01', $transaction->date->toDateString());
    }

    #[Test]
    public function an_opening_balance_dated_before_go_live_is_refused(): void
    {
        // M2.2's rule, enforced by the engine and inherited here: everything
        // before books_start_date belongs to whatever the workshop kept
        // previously, and arrives once, as this.
        $this->actingForTenant(
            $this->tenant,
            fn () => $this->tenant->update(['books_start_date' => '2026-04-01'])
        );

        $this->expectException(BooksClosedException::class);

        $this->import(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,15000.00
        CSV, '2026-03-31');
    }

    /* ---------------------------------------------------------------------
     | Provenance
     |-------------------------------------------------------------------- */

    #[Test]
    public function imported_transactions_carry_their_source_and_their_receipt(): void
    {
        $import = $this->import(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,15000.00
        CSV);

        $transaction = $this->actingForTenant($this->tenant, fn () => Transaction::query()->firstOrFail());

        $this->assertSame(TransactionType::Opening, $transaction->type);
        // The reason TransactionSource::Import has existed unused since M4.
        $this->assertSame('import', $transaction->source->value);
        $this->assertSame($import->id, $transaction->opening_import_id);
        $this->assertSame($this->owner->id, $transaction->created_by);
    }

    #[Test]
    public function an_import_receipt_cannot_be_re_pointed_at_other_postings(): void
    {
        // Write-once provenance: it may go null → set and never again, or a
        // receipt could claim postings it never made.
        $this->import(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,15000.00
        CSV);

        $this->expectException(TransactionImmutableException::class);

        $this->actingForTenant($this->tenant, function () {
            Transaction::query()->firstOrFail()->update(['opening_import_id' => 999]);
        });
    }

    #[Test]
    public function every_opening_record_is_scoped_to_its_own_workshop(): void
    {
        [$other] = $this->tenantWithUser([['*', '*']], 'Other Role');

        $this->import(<<<'CSV'
        kind,name,amount
        receivable,Sharma Motors,15000.00
        CSV);

        $this->assertSame('0.00', $this->balanceOf($other, SystemAccount::Receivables));
        $this->assertSame(0, $this->actingForTenant($other, fn () => Transaction::query()->count()));
        $this->assertBooksBalance($other, 'in a workshop that imported nothing');
    }
}
