<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\IndexAdvanceRequest;
use App\Http\Requests\Staff\StoreAdvanceRequest;
use App\Http\Requests\Transaction\ReverseTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Services\Staff\PayrollService;
use App\Services\Staff\AdvanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Money handed to an employee against a salary not yet earned — M22.
 *
 * ## Why these are transactions and not rows of their own
 *
 * Because an advance *is* a ledger event: cash leaves the till and an asset
 * appears. Giving it a table of its own would mean either posting it twice —
 * once as a row and once as a journal that could disagree with it — or not
 * posting it at all, which would leave the workshop's cash position wrong by
 * however much is out with its staff. So it is a `staff_advance` transaction with
 * `employee_id` stamped on it, and the outstanding figure is derived from those
 * rows rather than stored anywhere.
 *
 * ## Correcting one is a reversal
 *
 * A posted transaction refuses writes, so an advance typed wrong is reversed and
 * re-entered — the ledger's rule, unchanged. Note what makes that safe: recovery
 * reads posted advances only, so a reversed one stops counting against the
 * employee the instant it is cancelled, with nothing having to remember.
 *
 * ## Two grants meet here
 *
 * Reading is STAFF. Paying one and reversing it reach the ledger, so both
 * additionally require WRITE:TRANSACTIONS — see the routes.
 */
class StaffAdvanceController extends Controller
{
    public function __construct(
        private readonly AdvanceService $advances,
        private readonly PayrollService $payroll,
    ) {}

    /**
     * GET /api/v1/staff/advances
     *
     * The vouchers, newest first. Reversed ones stay on the list rather than
     * disappearing: money went out and came back, and a list that hid the pair
     * would be one nobody could reconcile against a cash box.
     */
    public function index(IndexAdvanceRequest $request): JsonResponse
    {
        $page = $this->advances->paginate($request->filters(), $request->perPage());

        $employeeIds = collect($page->items())
            ->pluck('employee_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return ApiResponse::success(
            TransactionResource::collection(collect($page->items()))->resolve(request()),
            null,
            200,
            [
                /*
                | What is currently out with each person on this page.
                |
                | Beside the vouchers rather than folded into them, because they
                | are different questions: a voucher says what was handed over on
                | a day, and this says what is left of it after payroll has taken
                | some back. One query for the whole page.
                */
                'outstanding' => $this->payroll->advanceOutstandingFor($employeeIds),
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

    /**
     * POST /api/v1/staff/advances
     *
     * Posted outright — there is no draft. An advance is cash in somebody's hand
     * at the moment they ask for it, and a draft one would be a promise sitting
     * in a queue while the money is already gone.
     */
    public function store(StoreAdvanceRequest $request): JsonResponse
    {
        $advance = $this->advances->pay($request->payload(), $request->user());

        return ApiResponse::created(
            new TransactionResource($advance),
            sprintf(
                'Advance of %s paid to %s.',
                $advance->total,
                $advance->employee?->name ?? 'staff',
            ),
            [
                // The employee's new position, so the screen that raised this can
                // repaint without a second round trip.
                'outstanding' => $this->payroll->advanceOutstandingFor(
                    array_filter([(int) $advance->employee_id])
                ),
            ],
        );
    }

    /**
     * POST /api/v1/staff/advances/{advance}/reverse
     *
     * Cancel an advance — a wrong amount, the wrong person, money that never
     * actually left the till.
     */
    public function reverse(ReverseTransactionRequest $request, int $advance): JsonResponse
    {
        $original = $this->advances->find($advance);

        $reversal = $this->advances->reverse(
            $advance,
            $request->input('date'),
            $request->input('reason'),
            $request->user(),
        );

        return ApiResponse::created(
            new TransactionResource($reversal),
            'Advance cancelled.',
            [
                'outstanding' => $this->payroll->advanceOutstandingFor(
                    array_filter([(int) $original->employee_id])
                ),
            ],
        );
    }
}
