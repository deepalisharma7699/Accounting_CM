<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\IndexPayrollRequest;
use App\Http\Requests\Staff\PreviewPayrollRequest;
use App\Http\Requests\Staff\StorePayrollRequest;
use App\Http\Requests\Transaction\ReverseTransactionRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\PayrollRunResource;
use App\Services\Staff\PayrollComputation;
use App\Services\Staff\PayrollService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Paying a month — M22.
 *
 * ## Preview, then post. There is nothing in between.
 *
 * `preview` is free, reads live attendance, and can be re-run all day. `store`
 * recomputes the same sheet from scratch and posts it. There is no draft
 * endpoint because there is no draft: a parked payroll sheet is a set of figures
 * derived from a register that keeps moving under it, and somebody would open a
 * fortnight-old one and pay a month that three subsequent absences had already
 * made wrong.
 *
 * The consequence, worth stating where a client can see it: **what the operator
 * saw is not what is posted.** The recomputation at post is the authority, and
 * the only thing carried over from the screen is the human decision — how much
 * of each advance to recover — which is the part a machine cannot re-derive.
 *
 * ## Two grants meet here
 *
 * Reading a run and previewing a month are STAFF. Posting one and reversing it
 * reach the ledger, so both additionally require WRITE:TRANSACTIONS — see the
 * routes. Somebody trusted to keep the staff list is not thereby trusted to move
 * cash out of the till.
 */
class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollService $payroll,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * GET /api/v1/staff/payroll
     */
    public function index(IndexPayrollRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->payroll->paginate($request->filters(), $request->perPage()),
            PayrollRunResource::class,
        );
    }

    /**
     * GET /api/v1/staff/payroll/{run}
     *
     * The run and every payslip on it — what the drawer reads.
     */
    public function show(int $run): JsonResponse
    {
        return ApiResponse::success(new PayrollRunResource($this->payroll->find($run)));
    }

    /**
     * POST /api/v1/staff/payroll/preview
     *
     * Compute a month without writing anything.
     *
     * A POST for a read, because the recovery overrides are a map that does not
     * belong in a query string. Nothing is written and nothing is reserved.
     */
    public function preview(PreviewPayrollRequest $request): JsonResponse
    {
        $sheet = $this->payroll->preview($request->period(), $request->recoveries());

        return ApiResponse::success(
            $this->rowsPayload($sheet['rows']),
            null,
            200,
            [
                'period' => $sheet['period'],
                'period_label' => $sheet['period_label'],
                'days' => $sheet['days'],
                'totals' => $sheet['totals'],
                /*
                | The month is already paid.
                |
                | Named rather than refused: looking at a month that has been run
                | is a legitimate thing to do, and the refusal belongs at the
                | moment somebody tries to post it. A client shows this as a
                | banner over the sheet, with the run it names.
                */
                'existing_run' => $sheet['existing_run'] === null
                    ? null
                    : (new PayrollRunResource($sheet['existing_run']))->resolve(request()),
            ],
        );
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * POST /api/v1/staff/payroll
     *
     * Pay a month. The sheet is recomputed here rather than taken from the
     * request — see the class note.
     */
    public function store(StorePayrollRequest $request): JsonResponse
    {
        $run = $this->payroll->post($request->payload(), $request->user());

        return ApiResponse::created(
            new PayrollRunResource($run),
            sprintf(
                '%s payroll posted — %s to %d staff.',
                $run->periodLabel(),
                $run->totals()['net'],
                $run->totals()['headcount'],
            ),
        );
    }

    /**
     * POST /api/v1/staff/payroll/{run}/reverse
     *
     * Cancel a run: the ledger entries are mirrored rather than deleted, and the
     * month is freed to be run again against the attendance as it now stands.
     *
     * The payslips are kept. They are the record of what was paid out and then
     * taken back, and somebody handed cash against one is entitled to have it
     * still exist — they simply stop counting for advance recovery, because that
     * read is scoped to live runs.
     */
    public function reverse(ReverseTransactionRequest $request, int $run): JsonResponse
    {
        $reversed = $this->payroll->reverse(
            $run,
            $request->input('date'),
            $request->input('reason'),
            $request->user(),
        );

        return ApiResponse::success(
            new PayrollRunResource($reversed),
            sprintf('%s payroll reversed. The month is free to run again.', $reversed->periodLabel()),
        );
    }

    /* ---------------------------------------------------------------------
     | Shapes
     |-------------------------------------------------------------------- */

    /**
     * One row of the sheet: who, what they earned and why, what is out with
     * them, and what this run proposes taking back.
     *
     * Somebody who earned nothing is present rather than dropped, and
     * `is_payable` says so. A daily-wage helper computing to zero because nobody
     * marked their days is the single likeliest thing to be wrong with a month,
     * and a row that silently vanished would take the evidence with it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsPayload(array $rows): array
    {
        return array_map(function (array $row) {
            /** @var PayrollComputation $computation */
            $computation = $row['computation'];

            return [
                'employee' => (new EmployeeResource($row['employee']))->resolve(request()),

                'salary_basis' => $computation->basis->value,
                'salary_basis_short' => $computation->basis->shortLabel(),
                'pay_rate' => $computation->rate->amount(),

                /*
                | Days as a number a person reads — 19, or 18.5. The half-days are
                | the storage unit because halves in integers are exact; they are
                | divided once, here, at the boundary.
                */
                'paid_days' => $computation->paidDays(),
                'period_days' => $computation->periodDays(),
                // Short of the whole month for a part-month joiner or leaver, and
                // the figure that explains a short payslip without anybody having
                // to work out why.
                'eligible_days' => $computation->eligibleDays(),

                'attendance' => $computation->attendance,
                // The one number a workshop should look at before posting: how
                // many days nobody touched. What it costs depends on the basis.
                'unmarked_days' => $computation->unmarkedDays(),

                'gross' => $computation->gross->amount(),
                'advance_outstanding' => $row['advance_outstanding'],
                'advance_recovered' => $row['advance_recovered'],
                'net' => $row['net'],

                'is_payable' => $computation->gross->isPositive(),
                'was_not_in_service' => $computation->wasNotInService(),
            ];
        }, $rows);
    }
}
