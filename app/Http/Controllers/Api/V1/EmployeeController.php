<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Enums\PaymentMode;
use App\Enums\SalaryBasis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\IndexEmployeeRequest;
use App\Http\Requests\Staff\IndexWorkRequest;
use App\Http\Requests\Staff\StoreEmployeeRequest;
use App\Http\Requests\Staff\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\PayrollLineResource;
use App\Http\Resources\StaffDesignationResource;
use App\Models\Employee;
use App\Services\Staff\AttendanceService;
use App\Services\Staff\DesignationService;
use App\Services\Staff\EmployeeService;
use App\Services\Staff\PayrollCalculator;
use App\Services\Staff\PayrollService;
use App\Services\Staff\WorkAttributionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * The people who work for the workshop — M22.
 *
 * ## One grant, and where the second one starts
 *
 * Everything here is STAFF, and STAFF alone. Reading somebody's record, their
 * rate, their attendance and what is out with them is all one authority, held by
 * the owner.
 *
 * The line is drawn where the *money* starts: paying an advance and posting a
 * payroll run reach the ledger, so both additionally require WRITE:TRANSACTIONS
 * — see the routes. That is the same boundary the workshop-jobs module draws
 * between recording a repair and billing it, and it exists so that a staff grant
 * cannot quietly become the ability to move cash out of the till.
 *
 * ## There is no `destroy` for anybody who has been paid
 *
 * Their payslips and their attendance would lose the name that explains them, so
 * they are archived — `PATCH {"left_on": "2026-09-12"}` — exactly as a party or
 * an account is. Delete only ever reaches a typo caught the same afternoon.
 */
class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly DesignationService $designations,
        private readonly AttendanceService $attendance,
        private readonly PayrollService $payroll,
        // What each person got through on the shop floor — M22. A staff question
        // answered from rows that hang off transactions, which is why it is read
        // here and written from the sale form.
        private readonly WorkAttributionService $attribution,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * GET /api/v1/staff
     *
     * Advances and attendance are opt-in — `with_advances=1`, `with_attendance=1`
     * — and each costs one extra query for the whole page rather than one per
     * row. The staff list wants both; a picker wants neither.
     */
    public function index(IndexEmployeeRequest $request): JsonResponse
    {
        $page = $this->employees->paginate($request->filters(), $request->perPage());
        $employees = collect($page->items());

        if ($request->wantsAdvances()) {
            $this->attachAdvances($employees);
        }

        if ($request->wantsAttendance()) {
            $this->attachAttendance($employees, $request->period());
        }

        return ApiResponse::paginated($page, EmployeeResource::class);
    }

    /**
     * GET /api/v1/staff/meta
     *
     * Everything a client needs to build the staff forms without hard-coding any
     * of it: the two salary bases, the six attendance states with the colour each
     * is painted in, the payment modes an advance can be handed over through, and
     * the workshop's own designations.
     *
     * The designations are here as well as on their own endpoint deliberately —
     * the module opens on a form that needs them, and a second round trip to fill
     * one select is a form that renders empty for a moment on a slow phone.
     *
     * The bases and the statuses are *code* and the designations are *data*, and
     * this endpoint is where that distinction is honoured rather than argued: a
     * client never writes out either list, so neither can go stale, and the one
     * that a workshop maintains is the one that arrives from a table.
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'salary_bases' => SalaryBasis::catalogue(),
            'attendance_statuses' => AttendanceStatus::catalogue(),
            'payment_modes' => array_map(fn (PaymentMode $mode) => [
                'value' => $mode->value,
                'label' => $mode->label(),
                'reference_label' => $mode->referenceLabel(),
                'requires_reference' => $mode->requiresReference(),
            ], PaymentMode::cases()),
            'designations' => StaffDesignationResource::collection(
                $this->designations->withCounts()
            )->resolve(request()),
        ]);
    }

    /**
     * GET /api/v1/staff/{employee}
     *
     * The whole picture in one round trip: the record, what is out with them,
     * how the month has gone, and their last year of payslips. One employee is
     * one row, so there is no page to spread the cost over and the drawer that
     * reads this wants all of it.
     */
    public function show(int $employee): JsonResponse
    {
        $record = $this->employees->find($employee);
        $records = collect([$record]);

        $this->attachAdvances($records);
        $this->attachAttendance($records, now()->format('Y-m'));

        return ApiResponse::success(
            new EmployeeResource($record),
            null,
            200,
            [
                'payslips' => PayrollLineResource::collection(
                    $this->payroll->historyFor($record->id)
                )->resolve(request()),
            ],
        );
    }

    /**
     * GET /api/v1/staff/{employee}/work
     *
     * How much work this person has got through, and the invoices behind it —
     * M22.
     *
     * Two figures, because they answer different questions and neither stands in
     * for the other. **Jobs** is throughput: eleven motors is eleven motors
     * whether they were small ones or not. **Value** is what those invoices came
     * to, which is the figure an owner reaches for and the one most easily
     * misread — a bill that is mostly bearings credits its fitter with the
     * bearings, because the document does not separate the labour from the parts.
     * Both are shown, so neither is mistaken for a measure of effort on its own.
     *
     * Its own endpoint rather than another block on `show`, because it takes a
     * period and paginates. Folding it in would mean every drawer read paid for
     * a page of invoices nobody had asked for yet.
     *
     * ## What it deliberately is not
     *
     * Not an input to pay. Nothing here reaches payroll, which computes from a
     * rate and an attendance sheet in one place — see {@see PayrollCalculator}.
     * A throughput figure that quietly became a piece rate would be a second
     * source of truth for wages, arrived at by accident.
     */
    public function work(IndexWorkRequest $request, int $employee): JsonResponse
    {
        // Through the service, so an id belonging to another workshop is a 404
        // here rather than an empty report that reads as "they did nothing".
        $record = $this->employees->find($employee);

        $page = $this->attribution->invoicesFor(
            $record->id,
            $request->from(),
            $request->to(),
            $request->perPage(),
        );

        return ApiResponse::success(
            $this->attribution->describeInvoices($record->id, collect($page->items())),
            null,
            200,
            [
                'summary' => $this->attribution->workFor($record->id, $request->from(), $request->to()),
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                    'has_more' => $page->hasMorePages(),
                ],
            ],
        );
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * POST /api/v1/staff
     */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new EmployeeResource($this->employees->create($request->payload())),
            'Added to the staff list.',
        );
    }

    /**
     * PATCH /api/v1/staff/{employee}
     *
     * Also the archive control: `{"left_on": "2026-09-12"}` takes somebody off
     * the day sheet and the next payroll, and `{"left_on": null}` puts them back.
     */
    public function update(UpdateEmployeeRequest $request, int $employee): JsonResponse
    {
        $record = $this->employees->update($employee, $request->payload());

        return ApiResponse::success(
            new EmployeeResource($record),
            $record->hasLeft() ? 'Marked as having left.' : 'Staff record updated.',
        );
    }

    /**
     * DELETE /api/v1/staff/{employee}
     *
     * Only ever reaches somebody nothing points at. Anybody with attendance, a
     * payslip or an advance behind them is refused with `EMPLOYEE_IN_USE` and an
     * explanation that names archiving instead.
     */
    public function destroy(int $employee): JsonResponse
    {
        $this->employees->delete($employee);

        return ApiResponse::message('Removed from the staff list.');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    /**
     * Hang what is out with each of them on the model for the resource to
     * serialise. Two queries for the whole collection, not two per row.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function attachAdvances(Collection $employees): void
    {
        $advances = $this->payroll->advanceOutstandingFor(
            $employees->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        foreach ($employees as $employee) {
            $employee->setAttribute('advance', $advances[(int) $employee->id] ?? null);
        }
    }

    /**
     * Hang how each of their months has gone. One query for the whole
     * collection.
     *
     * The period travels back with the counts rather than being assumed by the
     * client: a list fetched with `period=2026-08` and rendered under a heading
     * saying "this month" is the sort of thing nobody notices until a payslip is
     * queried.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function attachAttendance(Collection $employees, string $period): void
    {
        $start = PayrollCalculator::monthStart($period.'-01');
        $end = $start->endOfMonth();

        $counts = $this->attendance->summariesFor(
            $employees->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $start,
            $end,
        );

        foreach ($employees as $employee) {
            $employee->setAttribute('attendance', [
                'period' => $start->format('Y-m'),
                'period_label' => $start->format('F Y'),
                'days' => $start->daysInMonth,
                'counts' => $counts[(int) $employee->id] ?? [],
            ]);
        }
    }
}
