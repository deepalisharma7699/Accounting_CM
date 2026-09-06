<?php

namespace Tests\Feature\Console;

use App\Enums\PartyRole;
use App\Enums\SalaryBasis;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Models\WorkshopJobPart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The go-live cleanup — `2026_09_05_100003_clear_trading_data_keeping_staff_and_users`.
 *
 * A migration that deletes is only as good as the list of what it leaves behind,
 * and that list cannot be read off the file: it is a consequence of eleven
 * foreign keys, three of which point back into their own table under
 * `restrictOnDelete`. So a workshop's whole trading history is built here through
 * the endpoints the modules actually call — a purchase onto the shelf, a sale off
 * it with a fitter's name on it, a credit note against that sale's line, a
 * receipt allocated to the invoice, a shared link, a reversed journal, a job card
 * with parts on it, an opening import — the migration is run over it, and both
 * halves are asserted: nothing trading survives, and everything about the people
 * does, down to the ledger entries that paid them.
 *
 * The self-references are the reason for the credit note and the reversal
 * specifically. `transaction_lines.against_line_id` and `transactions.reverses_id`
 * are only populated by those two acts, and a delete that did not detach them
 * first would pass every other assertion here and fail on a real server.
 */
class ClearTradingDataMigrationTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private const MIGRATION = 'clear_trading_data_keeping_staff_and_users';

    private Tenant $tenant;

    private User $owner;

    private ItemVariant $bearing;

    private Party $customer;

    private Party $vendor;

    private int $fitterId;

    private int $winderDesignationId;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser();

        $this->bearing = $this->variantFor($this->tenant, 'part');

        [$this->customer, $this->vendor] = $this->actingForTenant($this->tenant, fn () => [
            Party::factory()->create([
                'name' => 'Ravi Rewinding Works',
                'roles' => [PartyRole::Customer->value],
                'state_code' => '27',
            ]),
            Party::factory()->create([
                'name' => 'Sharma Traders',
                'roles' => [PartyRole::Vendor->value],
                'state_code' => '27',
            ]),
        ]);
    }

    /* ---------------------------------------------------------------------
     | The two halves
     |-------------------------------------------------------------------- */

    #[Test]
    public function every_trace_of_trading_is_gone(): void
    {
        $this->buildAWorkshopsHistory();

        $this->assertGreaterThan(0, DB::table('stock_movements')->count(), 'precondition');

        $this->runTheMigration();

        foreach ([
            'parties',
            'items',
            'item_variants',
            'stock_movements',
            'transaction_lines',
            'transaction_allocations',
            'transaction_staff',
            'invoice_shares',
            'workshop_jobs',
            'workshop_job_parts',
            'opening_imports',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "[{$table}] still has rows");
        }
    }

    #[Test]
    public function only_the_staff_vouchers_are_left_in_the_ledger(): void
    {
        $this->buildAWorkshopsHistory();

        $before = DB::table('transactions')
            ->whereIn('type', [TransactionType::Payroll->value, TransactionType::StaffAdvance->value])
            ->pluck('id')
            ->all();

        $this->assertNotEmpty($before, 'precondition: staff vouchers were posted');

        $this->runTheMigration();

        $this->assertEqualsCanonicalizing(
            $before,
            DB::table('transactions')->pluck('id')->all(),
            'a transaction that is not payroll or an advance survived'
        );

        /*
        | The point of scoping the child deletes rather than truncating: half a
        | payroll voucher is worse than none. Every surviving entry and split
        | belongs to a surviving transaction, and each voucher still has its own.
        */
        $this->assertSame(0, DB::table('journal_entries')->whereNotIn('transaction_id', $before)->count());
        $this->assertSame(0, DB::table('transaction_payments')->whereNotIn('transaction_id', $before)->count());

        foreach ($before as $id) {
            $this->assertGreaterThan(
                0,
                DB::table('journal_entries')->where('transaction_id', $id)->count(),
                "staff voucher {$id} lost its ledger entries"
            );
        }
    }

    #[Test]
    public function the_people_and_what_they_were_paid_are_untouched(): void
    {
        $this->buildAWorkshopsHistory();

        $counts = $this->rowCounts([
            'users', 'roles', 'permissions', 'role_permission', 'tenants',
            'chart_of_accounts', 'employees', 'staff_designations',
            'staff_attendances', 'payroll_runs', 'payroll_lines',
            // The catalogue's vocabulary is configuration, not trading data.
            'item_categories', 'item_attributes', 'item_brands', 'units',
        ]);

        $this->runTheMigration();

        $this->assertSame($counts, $this->rowCounts(array_keys($counts)));
    }

    #[Test]
    public function numbering_restarts_except_for_the_series_still_in_use(): void
    {
        $this->buildAWorkshopsHistory();

        $this->assertGreaterThan(2, DB::table('document_sequences')->count(), 'precondition');

        $this->runTheMigration();

        /*
        | ADV and SAL are the numbers on the vouchers that survived. Resetting
        | them would issue a second ADV/26-27/1001 against a slip somebody signed.
        */
        $this->assertEqualsCanonicalizing(
            ['ADV', 'SAL'],
            DB::table('document_sequences')->pluck('series')->unique()->values()->all()
        );
    }

    #[Test]
    public function the_audit_trail_loses_only_the_records_that_no_longer_exist(): void
    {
        $this->buildAWorkshopsHistory();

        $employeeEntries = DB::table('audit_logs')->where('resource', 'employee')->count();
        $this->assertGreaterThan(0, $employeeEntries, 'precondition: staff writes were audited');

        $this->runTheMigration();

        $this->assertSame(
            0,
            DB::table('audit_logs')->whereIn('resource', ['party', 'item', 'variant', 'sale_attribution'])->count()
        );

        $this->assertSame($employeeEntries, DB::table('audit_logs')->where('resource', 'employee')->count());
    }

    #[Test]
    public function it_refuses_to_be_rolled_back(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->migration()->down();
    }

    /* ---------------------------------------------------------------------
     | Harness
     |-------------------------------------------------------------------- */

    /**
     * A workshop's first weeks, through the endpoints the modules call.
     *
     * Breadth is the point rather than realism: every table the migration
     * touches, and in particular every foreign key that could refuse a delete,
     * has to have at least one row in it before the migration runs.
     */
    private function buildAWorkshopsHistory(): void
    {
        $this->hireAndPayStaff();

        $this->buy('20', '500.00');
        $invoice = $this->sell('4', '900.00');

        // Populates `transaction_lines.against_line_id` — a self-reference.
        $this->api()->postJson("/api/v1/transactions/{$invoice}/return", [
            'lines' => [['line_no' => 1, 'quantity' => '1']],
            'client_ref' => (string) Str::uuid(),
        ])->assertCreated();

        // Populates `transaction_allocations`.
        $this->api()->postJson('/api/v1/transactions/receipt', [
            'date' => now()->toDateString(),
            'post' => true,
            'party_id' => $this->customer->id,
            'payments' => [['mode' => 'cash', 'amount' => '1000.00']],
            'allocations' => [['bill_transaction_id' => $invoice, 'amount' => '1000.00']],
        ])->assertCreated();

        // Populates `invoice_shares`.
        $this->api()->postJson("/api/v1/transactions/{$invoice}/share")->assertOk();

        // Populates `transactions.reverses_id` — the other self-reference.
        $journal = $this->postSimpleJournal(
            $this->tenant, SystemAccount::Cash, SystemAccount::OpeningBalanceEquity, '2500.00'
        );
        $this->api()->postJson("/api/v1/transactions/{$journal->id}/reverse")->assertCreated();

        $this->api()->postJson('/api/v1/transactions/expense', [
            'date' => now()->toDateString(),
            'post' => true,
            'account_id' => $this->accountFor($this->tenant, SystemAccount::MiscExpense)->id,
            'amount' => '750.00',
            'payments' => [['mode' => 'cash', 'amount' => '750.00']],
        ])->assertCreated();

        $this->jobCardWithParts();
        $this->anOpeningImport();
    }

    private function hireAndPayStaff(): void
    {
        $winder = $this->api()->postJson('/api/v1/staff/designations', [
            'name' => 'Winder',
            'track_on_sales' => true,
        ])->assertCreated()->json('data.id');

        $this->winderDesignationId = (int) $winder;

        $this->fitterId = (int) $this->api()->postJson('/api/v1/staff', [
            'name' => 'Ramesh Kumar',
            'salary_basis' => SalaryBasis::Monthly->value,
            'pay_rate' => '18000',
            'joined_on' => '2025-01-01',
            'designation_id' => $this->winderDesignationId,
        ])->assertCreated()->json('data.id');

        $this->api()->putJson('/api/v1/staff/attendance', [
            'date' => '2026-01-12',
            'rows' => [['employee_id' => $this->fitterId, 'status' => 'present']],
        ])->assertOk();

        $this->api()->postJson('/api/v1/staff/advances', [
            'employee_id' => $this->fitterId,
            'date' => '2026-01-15',
            'payments' => [['mode' => 'cash', 'amount' => '2000.00']],
        ])->assertCreated();

        $this->api()->postJson('/api/v1/staff/payroll', [
            'period' => '2026-01',
            'date' => '2026-02-07',
            'payments' => [['mode' => 'cash', 'amount' => '16000.00']],
            'recoveries' => [$this->fitterId => '2000.00'],
        ])->assertCreated();
    }

    private function buy(string $quantity, string $rate): int
    {
        return (int) $this->api()->postJson('/api/v1/transactions/purchase', [
            'date' => now()->toDateString(),
            'post' => true,
            'party_id' => $this->vendor->id,
            'items' => [[
                'variant_id' => $this->bearing->id,
                'quantity' => $quantity,
                'unit_price' => $rate,
            ]],
        ])->assertCreated()->json('data.id');
    }

    /** A sale with a name against it, so `transaction_staff` has a row. */
    private function sell(string $quantity, string $rate): int
    {
        return (int) $this->api()->postJson('/api/v1/transactions/sale', [
            'date' => now()->toDateString(),
            'post' => true,
            'party_id' => $this->customer->id,
            'items' => [[
                'variant_id' => $this->bearing->id,
                'quantity' => $quantity,
                'unit_price' => $rate,
            ]],
            'staff' => [[
                'designation_id' => $this->winderDesignationId,
                'employee_id' => $this->fitterId,
            ]],
        ])->assertCreated()->json('data.id');
    }

    /**
     * `workshop_job_parts` holds the only foreign key into `transaction_lines`
     * other than the self-reference, and `workshop_jobs` the only one into
     * `parties` other than a transaction's. Written through the factories
     * because the Jobs module's UI is switched off; the rows are the point.
     */
    private function jobCardWithParts(): void
    {
        $this->actingForTenant($this->tenant, function () {
            $job = WorkshopJob::factory()->create([
                'party_id' => $this->customer->id,
                'item_id' => $this->bearing->item_id,
            ]);

            WorkshopJobPart::factory()->create([
                'workshop_job_id' => $job->id,
                'item_id' => $this->bearing->item_id,
                'variant_id' => $this->bearing->id,
                'transaction_line_id' => DB::table('transaction_lines')->value('id'),
            ]);
        });
    }

    private function anOpeningImport(): void
    {
        DB::table('opening_imports')->insert([
            'tenant_id' => $this->tenant->id,
            'filename' => 'opening.csv',
            'fingerprint' => str_repeat('a', 64),
            'date' => '2026-01-01',
            'row_count' => 1,
            'imported_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runTheMigration(): void
    {
        $this->migration()->up();
    }

    private function migration(): object
    {
        $path = collect(glob(database_path('migrations/*_'.self::MIGRATION.'.php')))->firstOrFail();

        return require $path;
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function rowCounts(array $tables): array
    {
        return collect($tables)
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }

    private function api(): TestCase
    {
        return $this->withHeaders($this->authHeader($this->owner));
    }
}
