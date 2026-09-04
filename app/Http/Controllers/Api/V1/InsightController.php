<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionAction;
use App\Enums\PermissionResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportPeriodRequest;
use App\Services\Reporting\InsightService;
use App\Services\Reporting\Insights\StockInsights;
use App\Services\Reporting\ReportPeriod;
use App\Services\Rbac\AuthorizationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The insight module — M23.
 *
 * Nothing in this controller has numbers of its own. Every figure below is a sum
 * over `transaction_lines`, `stock_movements`, `journal_entries` or
 * `payroll_lines`, worked out when it is asked for — see {@see InsightService}.
 *
 * ## Why the panels are separate endpoints and the overview is not
 *
 * The overview is one screen painted once, so it is one request: five round
 * trips to fill it would be five chances for half of it to arrive. The panels
 * behind the tabs are the opposite case — a workshop that only ever opens the
 * ageing must not pay for a dead-stock scan, and §7.2 says a module opening must
 * not load another module's data. One endpoint per tab, fetched on the first
 * click of that tab and held from then on.
 *
 * ## Two grants, and the second is not a formality
 *
 * Everything here needs `READ:LEDGER`, which is the same authority the P&L and
 * the day book need and the one an owner holds. The **people** panel
 * additionally needs `READ:STAFF`, and that is a privacy boundary rather than a
 * convenience: STAFF is the one grant in this application withheld because of
 * what it reveals about individuals rather than because of what it lets somebody
 * do. The overview asks the same question and omits its wage tile entirely for a
 * caller who holds only the first — absent, not blanked.
 */
class InsightController extends Controller
{
    public function __construct(
        private readonly InsightService $insights,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * GET /api/v1/insights/meta
     *
     * What the client needs to draw the module before it has asked for
     * anything: the periods it may request, which tabs this session may see, and
     * the thresholds that decide what the words on the screen mean.
     *
     * The periods come from {@see ReportPeriod} rather than being written into
     * the client, because "this financial year" depends on the workshop's own
     * year-start setting — a copy in JavaScript would be right until somebody
     * changed it. Same file, same reason, as the reports screen.
     */
    public function meta(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'periods' => ReportPeriod::presets(),
            // The client hides the tab rather than letting somebody open a panel
            // that will 403. Presentation only — the endpoint behind it is
            // guarded regardless (§6.1, §6.2).
            'may_read_staff' => $this->mayReadStaff($request),
            'thresholds' => [
                // Published rather than hard-coded in the client, so the caption
                // under the dead-stock table and the query behind it can never
                // disagree about what "not moving" means.
                'dead_stock_days' => StockInsights::DEAD_AFTER_DAYS,
            ],
            'has_data' => $this->insights->hasPostedAnything(),
        ]);
    }

    /**
     * GET /api/v1/insights/overview
     *
     * The headline figures with their period-over-period deltas, the revenue and
     * margin trend, the goods/labour split, and the exception feed.
     */
    public function overview(ReportPeriodRequest $request): JsonResponse
    {
        $period = $request->period();

        return ApiResponse::success(
            $this->insights->overview($period, $this->mayReadStaff($request)),
            null,
            200,
            ['period' => $period->toArray()],
        );
    }

    /**
     * GET /api/v1/insights/sales
     *
     * Revenue and margin over time, the goods/labour mix, who bought the most,
     * which items actually earn, and everything that went out below cost.
     */
    public function sales(ReportPeriodRequest $request): JsonResponse
    {
        return $this->panel($this->insights->salesPanel($request->period()));
    }

    /**
     * GET /api/v1/insights/purchase
     *
     * The same arithmetic mirrored — what was bought, from whom, and what it
     * cost. Sales and Purchase are one class with a direction, exactly as the
     * two modules are one document engine with a direction.
     */
    public function purchase(ReportPeriodRequest $request): JsonResponse
    {
        return $this->panel($this->insights->purchasePanel($request->period()));
    }

    /**
     * GET /api/v1/insights/stock
     *
     * What the shelf is worth over time, how fast it turns, what has stopped
     * moving, what needs reordering, and what stock-takes wrote off.
     */
    public function stock(ReportPeriodRequest $request): JsonResponse
    {
        return $this->panel($this->insights->stockPanel($request->period()));
    }

    /**
     * GET /api/v1/insights/credit
     *
     * The ageing — receivable and payable — plus who to ring, how much was
     * collected against what was billed, and what customers have paid ahead.
     */
    public function credit(ReportPeriodRequest $request): JsonResponse
    {
        return $this->panel($this->insights->creditPanel($request->period()));
    }

    /**
     * GET /api/v1/insights/people
     *
     * What the workshop's people cost, what is out with them in advances, who
     * turned up, and what work each was credited with.
     *
     * Gated on `READ:STAFF` as well as `READ:LEDGER` at the route. See the class
     * note: that is a privacy line, not an authority one.
     */
    public function people(ReportPeriodRequest $request): JsonResponse
    {
        return $this->panel($this->insights->peoplePanel($request->period()));
    }

    /**
     * @param  array<string, mixed>  $panel
     */
    private function panel(array $panel): JsonResponse
    {
        $period = $panel['period'];
        unset($panel['period']);

        return ApiResponse::success($panel, null, 200, ['period' => $period]);
    }

    /**
     * May this session see what individual people are paid?
     *
     * Asked of {@see AuthorizationService} rather than inferred from the route,
     * because the overview is reachable with `READ:LEDGER` alone and still has
     * to decide whether to include a wage figure in it.
     */
    private function mayReadStaff(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $this->authorization->userHasPermission(
            $user,
            PermissionAction::Read->value,
            PermissionResource::Staff->value,
        );
    }
}
