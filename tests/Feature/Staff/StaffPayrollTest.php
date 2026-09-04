<?php

namespace Tests\Feature\Staff;

use App\Enums\AttendanceStatus;
use App\Enums\PayrollRunStatus;
use App\Enums\SalaryBasis;
use App\Enums\SystemAccount;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Staff, attendance, payroll and advances — M22.
 *
 * Four things are being established here, and everything below is a variation on
 * one of them.
 *
 * **An unmarked day is not a blank.** It is paid on a monthly salary and unpaid
 * on a daily wage, and that single rule decides most of what a workshop's payroll
 * comes to — because most days are unmarked most of the time.
 *
 * **The month is its own denominator.** A monthly salary pro-rates over the days
 * the month actually has, so the same salary is a different day rate in February
 * and in March.
 *
 * **An advance is an asset, and it is derived.** What is out with somebody is the
 * posted advances less what posted payroll has recovered — so reversing either
 * side moves the figure with nothing having to remember.
 *
 * **A run is a fact.** There is no draft, one live run per month, and correcting
 * one means reversing it — which frees the month.
 */
class StaffPayrollTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithTenancy;
    use RefreshDatabase;

    /**
     * A month that is safely in the past whichever day the suite runs on, so
     * nothing here depends on the calendar. Thirty-one days, which is what makes
     * the pro-rata arithmetic below worth checking.
     */
    private const MONTH = '2026-01';

    private const DAYS_IN_MONTH = 31;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'STAFF'], ['WRITE', 'STAFF'], ['UPDATE', 'STAFF'], ['DELETE', 'STAFF'],
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'], ['UPDATE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'],
        ]);
    }

    /* ---------------------------------------------------------------------
     | The record
     |-------------------------------------------------------------------- */

    #[Test]
    public function somebody_is_added_with_a_basis_and_a_rate(): void
    {
        $response = $this->addStaff(['name' => 'Ravi Sharma', 'pay_rate' => '18000'])
            ->assertCreated();

        $response->assertJsonPath('data.name', 'Ravi Sharma')
            ->assertJsonPath('data.salary_basis', 'monthly')
            // A decimal string, never a JSON number — a JSON number is parsed
            // straight back into a float by every client that receives it.
            ->assertJsonPath('data.pay_rate', '18000.00');
    }

    #[Test]
    public function two_people_cannot_share_one_name(): void
    {
        $this->addStaff(['name' => 'Ramesh'])->assertCreated();

        /*
        | Not tidiness. Two rows called "Ramesh" means one of them is marked
        | present every day and the other is paid nothing, and both look right on
        | their own screen.
        */
        $this->addStaff(['name' => 'Ramesh'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EMPLOYEE_NAME_TAKEN');
    }

    #[Test]
    public function a_designation_from_another_workshop_is_not_adopted(): void
    {
        [$otherTenant] = $this->tenantWithUser([['READ', 'STAFF']], 'Other Role');

        $foreign = $this->actingForTenant($otherTenant, fn () => \App\Models\StaffDesignation::create([
            'name' => 'Winder',
            'is_active' => true,
        ]));

        /*
        | Resolved through the tenant-scoped repository rather than trusted, so an
        | id belonging to another workshop reads as "not found" and becomes null
        | rather than reaching the column — the foreign key alone would accept it.
        */
        $this->addStaff(['name' => 'Asha', 'designation_id' => $foreign->id])
            ->assertCreated()
            ->assertJsonPath('data.designation_id', null);
    }

    #[Test]
    public function leaving_takes_somebody_off_the_list_without_deleting_them(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi'])->json('data');

        $this->api()->patchJson("/api/v1/staff/{$ravi['id']}", ['left_on' => '2026-01-20'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.left_on', '2026-01-20');

        // And coming back is the same field, cleared.
        $this->api()->patchJson("/api/v1/staff/{$ravi['id']}", ['left_on' => null])
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.left_on', null);
    }

    /* ---------------------------------------------------------------------
     | Attendance
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_day_sheet_offers_everybody_who_was_on_the_payroll_that_day(): void
    {
        $this->addStaff(['name' => 'Ravi', 'joined_on' => '2025-01-01']);
        $this->addStaff(['name' => 'Later', 'joined_on' => '2026-02-01']);

        $sheet = $this->api()->getJson('/api/v1/staff/attendance?date=2026-01-15')
            ->assertOk()
            ->json('data');

        // Somebody who joins next month is active today and was not on the
        // payroll on the day being marked.
        $this->assertCount(1, $sheet);
        $this->assertSame('Ravi', $sheet[0]['employee']['name']);
        // Unmarked is returned as unmarked rather than defaulted to present:
        // what it is worth depends on the basis, and that is decided in one place.
        $this->assertNull($sheet[0]['status']);
    }

    #[Test]
    public function saving_the_same_day_twice_leaves_one_mark_each(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi'])->json('data');

        $this->mark('2026-01-15', [['employee_id' => $ravi['id'], 'status' => 'absent']])->assertOk();
        $this->mark('2026-01-15', [['employee_id' => $ravi['id'], 'status' => 'half_day']])->assertOk();

        // An upsert, not a second row — the day sheet is corrected far more often
        // than it is filled in for the first time.
        $this->assertDatabaseCount('staff_attendances', 1);
        $this->assertDatabaseHas('staff_attendances', [
            'employee_id' => $ravi['id'],
            'status' => AttendanceStatus::HalfDay->value,
        ]);
    }

    #[Test]
    public function a_null_status_clears_the_mark_rather_than_being_ignored(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi'])->json('data');

        $this->mark('2026-01-15', [['employee_id' => $ravi['id'], 'status' => 'absent']])->assertOk();

        /*
        | Going back to unmarked is a correction somebody genuinely makes, and
        | without it a mis-tap could only be replaced, never undone. Unmarked is
        | the absence of a row — a status meaning "no status" would be a second
        | way to say the same thing.
        */
        $this->mark('2026-01-15', [['employee_id' => $ravi['id'], 'status' => null]])->assertOk();

        $this->assertDatabaseCount('staff_attendances', 0);
    }

    #[Test]
    public function a_mark_for_somebody_elses_employee_is_dropped(): void
    {
        [$otherTenant] = $this->tenantWithUser([['READ', 'STAFF']], 'Other Role');

        $foreign = $this->actingForTenant($otherTenant, fn () => Employee::create([
            'name' => 'Theirs',
            'salary_basis' => SalaryBasis::Monthly->value,
            'pay_rate' => '1000',
            'joined_on' => '2025-01-01',
            'is_active' => true,
        ]));

        $ravi = $this->addStaff(['name' => 'Ravi'])->json('data');

        $this->mark('2026-01-15', [
            ['employee_id' => $ravi['id'], 'status' => 'present'],
            ['employee_id' => $foreign->id, 'status' => 'present'],
        ])->assertOk();

        /*
        | The write is an upsert that goes round the tenant scope, so resolving
        | the ids against the scoped repository first is the only thing standing
        | between an id from another workshop and a row in these books.
        */
        $this->assertDatabaseCount('staff_attendances', 1);
        $this->assertDatabaseMissing('staff_attendances', ['employee_id' => $foreign->id]);
    }

    /* ---------------------------------------------------------------------
     | The arithmetic
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_unmarked_month_pays_a_monthly_salary_in_full(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000'])->json('data');

        /*
        | A monthly salary is owed unless something is recorded against it.
        | Treating silence as absence would dock a fitter three days' pay because
        | nobody opened the screen, and the employee would be the one to discover
        | it.
        */
        $row = $this->previewRowFor($ravi['id']);

        $this->assertSame('18000.00', $row['gross']);
        $this->assertSame(31.0, (float) $row['paid_days']);
        $this->assertSame(31, $row['unmarked_days']);
    }

    #[Test]
    public function an_unmarked_month_pays_a_daily_wage_nothing(): void
    {
        $asha = $this->addStaff([
            'name' => 'Asha',
            'salary_basis' => SalaryBasis::Daily->value,
            'pay_rate' => '550',
        ])->json('data');

        /*
        | The mirror of the case above, and the reason the rule lives on the
        | basis rather than in the calculator: a daily wage is earned by turning
        | up, and the mark is the evidence that somebody did.
        */
        $row = $this->previewRowFor($asha['id']);

        $this->assertSame('0.00', $row['gross']);
        $this->assertFalse($row['is_payable']);
    }

    #[Test]
    public function absences_and_half_days_come_off_a_monthly_salary_pro_rata(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000'])->json('data');

        $this->mark('2026-01-07', [['employee_id' => $ravi['id'], 'status' => 'absent']]);
        $this->mark('2026-01-08', [['employee_id' => $ravi['id'], 'status' => 'absent']]);
        $this->mark('2026-01-09', [['employee_id' => $ravi['id'], 'status' => 'half_day']]);

        // 18,000 × 57 half-days ÷ 62 half-days, rounded half up, in paise.
        $row = $this->previewRowFor($ravi['id']);

        $this->assertSame('16548.39', $row['gross']);
        $this->assertSame(28.5, (float) $row['paid_days']);
    }

    #[Test]
    public function a_daily_wage_is_paid_for_the_days_marked_and_not_for_rest_days(): void
    {
        $asha = $this->addStaff([
            'name' => 'Asha',
            'salary_basis' => SalaryBasis::Daily->value,
            'pay_rate' => '550',
        ])->json('data');

        foreach (range(1, 20) as $day) {
            $this->mark(sprintf('2026-01-%02d', $day), [
                ['employee_id' => $asha['id'], 'status' => 'present'],
            ]);
        }

        $this->mark('2026-01-21', [['employee_id' => $asha['id'], 'status' => 'half_day']]);
        $this->mark('2026-01-22', [['employee_id' => $asha['id'], 'status' => 'paid_leave']]);
        $this->mark('2026-01-23', [['employee_id' => $asha['id'], 'status' => 'holiday']]);
        $this->mark('2026-01-24', [['employee_id' => $asha['id'], 'status' => 'week_off']]);

        /*
        | 20 + ½ + 1 = 21.5 days. A holiday and a week off are unpaid on a daily
        | wage — the shop being shut is not a day worked — but a paid leave is
        | paid on both bases, which is exactly what makes it a different status
        | rather than a synonym.
        */
        $row = $this->previewRowFor($asha['id']);

        $this->assertSame(21.5, (float) $row['paid_days']);
        $this->assertSame('11825.00', $row['gross']);
    }

    #[Test]
    public function somebody_who_joined_mid_month_is_paid_for_the_part_they_were_here(): void
    {
        $sunil = $this->addStaff([
            'name' => 'Sunil',
            'pay_rate' => '12000',
            'joined_on' => '2026-01-12',
        ])->json('data');

        // Twenty of thirty-one days: 12,000 × 40 ÷ 62 = 7,741.935…, rounded half
        // up exactly once, at the end.
        $row = $this->previewRowFor($sunil['id']);

        $this->assertSame(20.0, (float) $row['eligible_days']);
        $this->assertSame('7741.94', $row['gross']);
    }

    #[Test]
    public function the_month_is_its_own_denominator(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000'])->json('data');

        $this->mark('2026-02-05', [['employee_id' => $ravi['id'], 'status' => 'absent']]);
        $this->mark('2026-01-05', [['employee_id' => $ravi['id'], 'status' => 'absent']]);

        $january = $this->previewRowFor($ravi['id'], '2026-01');
        $february = $this->previewRowFor($ravi['id'], '2026-02');

        /*
        | One absence in each, and they are not worth the same: a day is 1/31 of
        | January and 1/28 of February. Pro-rating against a fixed 30 would pay a
        | month's salary and a day extra every long month.
        */
        $this->assertSame('17419.35', $january['gross']);
        $this->assertSame('17357.14', $february['gross']);
    }

    /* ---------------------------------------------------------------------
     | Advances
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_advance_is_an_asset_and_names_the_person_it_went_to(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi'])->json('data');

        $advance = $this->payAdvance($ravi['id'], '5000')->assertCreated()->json('data');

        $this->assertSame(TransactionType::StaffAdvance->value, $advance['type']);
        $this->assertSame($ravi['id'], $advance['employee_id']);
        // Its own numbering series — "which advance was that" is asked about a
        // slip somebody signed.
        $this->assertStringStartsWith('ADV/', (string) $advance['doc_no']);

        // Dr Staff Advance, Cr Cash. An asset, not an expense: the workshop is
        // owed it back.
        $this->assertSame('5000.00', $this->balanceOf($this->tenant, SystemAccount::StaffAdvance));
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::SalaryExpense));
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function what_is_out_with_somebody_is_derived_and_not_stored(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi'])->json('data');

        $this->payAdvance($ravi['id'], '5000');
        $this->payAdvance($ravi['id'], '3000');

        $this->assertSame('8000.00', $this->outstandingFor($ravi['id']));

        // There is no column to drift: the figure is the posted advances less
        // what posted payroll has recovered, computed on every read.
        $this->assertArrayNotHasKey(
            'advance_outstanding',
            Employee::withoutGlobalScopes()->find($ravi['id'])->getAttributes(),
        );
    }

    #[Test]
    public function cancelling_an_advance_stops_it_counting_immediately(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi'])->json('data');
        $advance = $this->payAdvance($ravi['id'], '5000')->json('data');

        $this->api()->postJson("/api/v1/staff/advances/{$advance['id']}/reverse", [])
            ->assertCreated();

        /*
        | Nothing had to remember. The read is scoped to posted advances, so a
        | reversed one falls out of it — which is why the recovery on the next
        | payroll cannot chase money that came back.
        */
        $this->assertSame('0.00', $this->outstandingFor($ravi['id']));
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::StaffAdvance));
        $this->assertBooksBalance($this->tenant);
    }

    /* ---------------------------------------------------------------------
     | Posting a month
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_run_posts_one_voucher_and_a_payslip_each(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000'])->json('data');
        $asha = $this->addStaff([
            'name' => 'Asha',
            'salary_basis' => SalaryBasis::Daily->value,
            'pay_rate' => '500',
        ])->json('data');

        foreach (range(1, 10) as $day) {
            $this->mark(sprintf('2026-01-%02d', $day), [
                ['employee_id' => $asha['id'], 'status' => 'present'],
            ]);
        }

        // 18,000 + 5,000 = 23,000.
        $run = $this->postPayroll([['mode' => 'cash', 'amount' => '23000']])
            ->assertCreated()
            ->json('data');

        $this->assertSame('23000.00', $run['gross']);
        $this->assertSame('23000.00', $run['net']);
        $this->assertSame(2, $run['headcount']);
        $this->assertStringStartsWith('SAL/', (string) $run['transaction']['doc_no']);

        /*
        | One voucher for the whole run, not one per employee. That is what the
        | event is — a workshop pays its staff on the 7th — and it also keeps
        | every wage in the building out of a ledger that READ:LEDGER opens.
        */
        $this->assertSame(
            1,
            Transaction::withoutGlobalScopes()
                ->where('type', TransactionType::Payroll->value)
                ->count(),
        );

        $this->assertSame('23000.00', $this->balanceOf($this->tenant, SystemAccount::SalaryExpense));
        $this->assertBooksBalance($this->tenant);

        // Who got what is only here — it is not recoverable from three ledger
        // lines.
        $this->assertDatabaseHas('payroll_lines', ['employee_name' => 'Ravi', 'gross' => '18000.00']);
        $this->assertDatabaseHas('payroll_lines', ['employee_name' => 'Asha', 'gross' => '5000.00']);
        $this->assertSame(0, \App\Models\PayrollLine::withoutGlobalScopes()
            ->where('employee_id', $ravi['id'])
            ->where('gross', '0.00')
            ->count());
    }

    #[Test]
    public function a_payroll_run_recovers_advances_against_the_salary_it_pays(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000'])->json('data');

        $this->payAdvance($ravi['id'], '5000');

        $run = $this->postPayroll([['mode' => 'cash', 'amount' => '13000']])
            ->assertCreated()
            ->json('data');

        $this->assertSame('18000.00', $run['gross']);
        $this->assertSame('5000.00', $run['advance_recovered']);
        $this->assertSame('13000.00', $run['net']);

        /*
        | The recovery credits Staff Advance rather than reducing the expense.
        | Netting it off the cost would understate what the workshop spends on
        | wages by exactly the amount it lends its staff.
        */
        $this->assertSame('18000.00', $this->balanceOf($this->tenant, SystemAccount::SalaryExpense));
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::StaffAdvance));
        $this->assertSame('0.00', $this->outstandingFor($ravi['id']));
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_recovery_can_never_exceed_what_was_earned(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi', 'pay_rate' => '6000'])->json('data');

        $this->payAdvance($ravi['id'], '20000');

        /*
        | The rest stays outstanding and comes off next month. A payslip that
        | ended with the employee owing the workshop money is not something a
        | payroll run can express.
        */
        $row = $this->previewRowFor($ravi['id']);

        $this->assertSame('6000.00', $row['advance_recovered']);
        $this->assertSame('0.00', $row['net']);

        // A run that recovers everything hands over nothing, and posts anyway:
        // `Dr Salary Expense / Cr Staff Advance`, no cash.
        $this->postPayroll([])->assertCreated();

        $this->assertSame('14000.00', $this->outstandingFor($ravi['id']));
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_payment_that_does_not_cover_the_month_is_refused(): void
    {
        $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000']);

        $this->postPayroll([['mode' => 'cash', 'amount' => '10000']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAYROLL_DOES_NOT_SETTLE');

        // Nothing half-written: the run, its payslips and the voucher commit
        // together or not at all.
        $this->assertDatabaseCount('payroll_runs', 0);
        $this->assertDatabaseCount('payroll_lines', 0);
    }

    #[Test]
    public function a_month_can_only_be_paid_once(): void
    {
        $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000']);

        $this->postPayroll([['mode' => 'cash', 'amount' => '18000']])->assertCreated();

        // Running it again would pay everybody twice, and nothing about the first
        // run would look wrong afterwards.
        $this->postPayroll([['mode' => 'cash', 'amount' => '18000']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PAYROLL_MONTH_ALREADY_RUN');
    }

    #[Test]
    public function a_month_that_has_not_started_cannot_be_run(): void
    {
        $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000']);

        $next = now()->addMonths(2)->format('Y-m');

        /*
        | Not pedantry: it would compute every daily-wage employee at zero and
        | every monthly one at a full salary for days nobody has worked, and it
        | would take the month out of circulation so the real run is refused when
        | it comes round.
        */
        $this->api()->postJson('/api/v1/staff/payroll', [
            'period' => $next,
            'payments' => [['mode' => 'cash', 'amount' => '18000']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAYROLL_MONTH_NOT_STARTED');
    }

    #[Test]
    public function reversing_a_run_frees_the_month_and_puts_the_advances_back(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000'])->json('data');

        $this->payAdvance($ravi['id'], '5000');

        $run = $this->postPayroll([['mode' => 'cash', 'amount' => '13000']])->json('data');

        $this->api()->postJson("/api/v1/staff/payroll/{$run['id']}/reverse", [])
            ->assertOk()
            ->assertJsonPath('data.status', PayrollRunStatus::Reversed->value);

        /*
        | Nothing is deleted from a book of account: the entries are cancelled by
        | their mirror image, and the payslips are kept — they are the record of
        | what was paid out and then taken back. They stop counting because the
        | read is scoped to live runs.
        */
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::SalaryExpense));
        $this->assertSame('5000.00', $this->outstandingFor($ravi['id']));
        $this->assertDatabaseCount('payroll_lines', 1);
        $this->assertBooksBalance($this->tenant);

        // And the month is free again.
        $this->postPayroll([['mode' => 'cash', 'amount' => '13000']])->assertCreated();
    }

    #[Test]
    public function a_run_is_dated_when_the_money_moved_and_belongs_to_the_month_it_pays_for(): void
    {
        $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000']);

        $run = $this->api()->postJson('/api/v1/staff/payroll', [
            'period' => self::MONTH,
            // A workshop paying January wages on 7 February is posting a
            // February cash movement.
            'date' => '2026-02-07',
            'payments' => [['mode' => 'cash', 'amount' => '18000']],
        ])->assertCreated()->json('data');

        $this->assertSame('2026-01', $run['period']);
        $this->assertSame('2026-02-07', $run['transaction']['date']);

        $this->assertSame(
            '2026-01-01',
            PayrollRun::withoutGlobalScopes()->find($run['id'])->period_month->toDateString(),
        );
    }

    #[Test]
    public function a_repeated_post_returns_the_run_it_already_made(): void
    {
        $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000']);

        $reference = (string) \Illuminate\Support\Str::uuid();

        $body = [
            'period' => self::MONTH,
            'payments' => [['mode' => 'cash', 'amount' => '18000']],
            'client_ref' => $reference,
        ];

        $first = $this->api()->postJson('/api/v1/staff/payroll', $body)->assertCreated()->json('data');
        $second = $this->api()->postJson('/api/v1/staff/payroll', $body)->json('data');

        /*
        | The single request in this application where a double submit matters
        | most: the second tap must not pay everybody twice. It gets the run the
        | first one made.
        */
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('payroll_runs', 1);
        $this->assertDatabaseCount('payroll_lines', 1);
    }

    /* ---------------------------------------------------------------------
     | Deletion, and the archive that replaces it
     |-------------------------------------------------------------------- */

    #[Test]
    public function somebody_who_has_been_paid_cannot_be_deleted(): void
    {
        $ravi = $this->addStaff(['name' => 'Ravi', 'pay_rate' => '18000'])->json('data');

        $this->postPayroll([['mode' => 'cash', 'amount' => '18000']])->assertCreated();

        // Their payslip would lose the name that explains it, so the refusal
        // names archiving instead.
        $this->api()->deleteJson("/api/v1/staff/{$ravi['id']}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EMPLOYEE_IN_USE')
            ->assertJsonPath('error.details.archive_instead', true);
    }

    #[Test]
    public function a_typo_caught_the_same_afternoon_can_be_deleted(): void
    {
        $typo = $this->addStaff(['name' => 'Rvai'])->json('data');

        $this->api()->deleteJson("/api/v1/staff/{$typo['id']}")->assertOk();

        $this->assertDatabaseCount('employees', 0);
    }

    /* ---------------------------------------------------------------------
     | The grant
     |-------------------------------------------------------------------- */

    #[Test]
    public function reading_the_staff_needs_the_staff_grant(): void
    {
        [, $clerk] = $this->tenantWithUser(
            [['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS']],
            'Clerk Role',
        );

        /*
        | The one grant in this application withheld for privacy rather than for
        | authority: what each person earns is not something the clerk at the
        | counter needs in order to capture the day's transactions.
        */
        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/staff')
            ->assertForbidden();
    }

    #[Test]
    public function posting_payroll_needs_the_transactions_grant_as_well(): void
    {
        [$tenant, $keeper] = $this->tenantWithUser(
            [['READ', 'STAFF'], ['WRITE', 'STAFF'], ['UPDATE', 'STAFF']],
            'Keeper Role',
        );

        $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/staff', [
                'name' => 'Ravi',
                'salary_basis' => SalaryBasis::Monthly->value,
                'pay_rate' => '18000',
            ])
            ->assertCreated();

        /*
        | Holding STAFF grants the record, not the money. A staff grant that
        | quietly conferred the ability to move cash out of the till would be a
        | hole in this model rather than a convenience — the same boundary the
        | workshop-jobs module draws between recording a repair and billing it.
        */
        $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/staff/payroll', [
                'period' => self::MONTH,
                'payments' => [['mode' => 'cash', 'amount' => '18000']],
            ])
            ->assertForbidden();

        $this->assertSame(0, $this->actingForTenant(
            $tenant,
            fn () => PayrollRun::query()->count(),
        ));
    }

    /* ---------------------------------------------------------------------
     | Tenancy
     |-------------------------------------------------------------------- */

    #[Test]
    public function one_workshop_never_sees_anothers_staff(): void
    {
        $this->addStaff(['name' => 'Ours'])->assertCreated();

        [, $otherOwner] = $this->tenantWithUser(
            [['READ', 'STAFF'], ['WRITE', 'STAFF']],
            'Other Owner Role',
        );

        $theirs = $this->withHeaders($this->authHeader($otherOwner))
            ->getJson('/api/v1/staff')
            ->assertOk()
            ->json('data');

        $this->assertSame([], $theirs);
    }

    /* ---------------------------------------------------------------------
     | The API, as the module reaches it
     |-------------------------------------------------------------------- */

    private function api(): TestCase
    {
        return $this->withHeaders($this->authHeader($this->owner));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function addStaff(array $overrides = []): TestResponse
    {
        return $this->api()->postJson('/api/v1/staff', array_merge([
            'name' => 'Somebody',
            'salary_basis' => SalaryBasis::Monthly->value,
            'pay_rate' => '10000',
            'joined_on' => '2025-01-01',
        ], $overrides));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function mark(string $date, array $rows): TestResponse
    {
        return $this->api()->putJson('/api/v1/staff/attendance', [
            'date' => $date,
            'rows' => $rows,
        ]);
    }

    private function payAdvance(int $employeeId, string $amount): TestResponse
    {
        return $this->api()->postJson('/api/v1/staff/advances', [
            'employee_id' => $employeeId,
            'date' => '2026-01-15',
            'payments' => [['mode' => 'cash', 'amount' => $amount]],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function postPayroll(array $payments, string $period = self::MONTH): TestResponse
    {
        return $this->api()->postJson('/api/v1/staff/payroll', [
            'period' => $period,
            'date' => '2026-02-07',
            'payments' => $payments,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function previewRowFor(int $employeeId, string $period = self::MONTH): array
    {
        $rows = $this->api()->postJson('/api/v1/staff/payroll/preview', ['period' => $period])
            ->assertOk()
            ->json('data');

        foreach ($rows as $row) {
            if ((int) $row['employee']['id'] === $employeeId) {
                return $row;
            }
        }

        $this->fail("No payroll row for employee {$employeeId} in {$period}.");
    }

    private function outstandingFor(int $employeeId): string
    {
        return (string) $this->api()->getJson('/api/v1/staff/'.$employeeId)
            ->assertOk()
            ->json('data.advance.outstanding');
    }
}
