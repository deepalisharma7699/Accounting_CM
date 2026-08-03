<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionAction;
use App\Enums\PermissionResource;
use App\Enums\StockMovementType;
use App\Enums\SystemAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\IndexStockRequest;
use App\Http\Requests\Stock\StockCardRequest;
use App\Http\Resources\StockMovementResource;
use App\Http\Resources\StockPositionResource;
use App\Services\Accounting\ChartOfAccountService;
use App\Services\Accounting\LedgerService;
use App\Services\Inventory\ItemVariantService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Rbac\AuthorizationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * What is on the shelf, what it is worth, and how it got there.
 *
 * Read-only, and that is structural rather than a simplification: nothing writes
 * to `stock_movements` except the posting engine. Stock is *moved* by posting a
 * transaction — a purchase, a sale, an adjustment — so the write routes for it
 * live on {@see TransactionController}, guarded by WRITE:TRANSACTIONS. A stock
 * screen with an "edit quantity" button would be a second write path, and the
 * whole module is built on there not being one.
 */
class StockController extends Controller
{
    public function __construct(
        private readonly StockLedgerService $stock,
        private readonly ItemVariantService $variants,
        private readonly LedgerService $ledger,
        private readonly ChartOfAccountService $accounts,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * GET /api/v1/stock
     *
     * Every inventoried variant with its position. Paged after the positions are
     * computed rather than before — see
     * {@see StockLedgerService::report()} on why "what is running out" cannot be
     * a page the database chose.
     */
    public function index(IndexStockRequest $request): JsonResponse
    {
        $report = $this->stock->report($request->filters());
        $page = $this->paginate($report['rows'], $request->perPage(), $request);

        $rows = array_map(
            fn (array $row) => (new StockPositionResource($row['variant'], $row['position']))->resolve($request),
            $page->items(),
        );

        return ApiResponse::success($rows, null, 200, [
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'has_more' => $page->hasMorePages(),
            ],
            // Over everything the text filters matched, not over the page and not
            // over what survives the status filter — "3 low" has to still say 3
            // after somebody clicks it.
            'totals' => [
                'quantity' => $report['totals']['quantity']->amount(),
                'value' => $report['totals']['value']->amount(),
                'variants' => $report['totals']['variants'],
                'low' => $report['totals']['low'],
                'negative' => $report['totals']['negative'],
                'out_of_stock' => $report['totals']['out_of_stock'],
            ],
        ]);
    }

    /**
     * GET /api/v1/stock/summary
     *
     * The headline figures, plus — for anyone allowed to read the books — the
     * reconciliation that matters most in this module: **the total value of the
     * shelf against the balance of the Inventory account.**
     *
     * The two are written in the same database transaction from the same figure,
     * so they agree by construction for everything that goes through a posting
     * template. What they cannot be protected from is a manual journal posted
     * directly to Inventory, which M4 deliberately allows because it is the
     * correction mechanism for everything else. This is where that shows up —
     * which is the whole reason the panel exists rather than an assertion
     * somewhere that would simply refuse.
     */
    public function summary(Request $request): JsonResponse
    {
        $totals = $this->stock->totals();

        $payload = [
            'quantity' => $totals['quantity']->amount(),
            'value' => $totals['value']->amount(),
        ];

        if ($this->canReadLedger($request)) {
            $account = $this->accounts->system(SystemAccount::Inventory);
            $balance = $this->ledger->balanceFor($account);

            $payload['inventory_account'] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'balance' => $balance->amount(),
            ];

            $payload['difference'] = $totals['value']->minus($balance)->amount();
            $payload['reconciles'] = $totals['value']->equals($balance);
        }

        return ApiResponse::success($payload);
    }

    /**
     * GET /api/v1/stock/meta
     *
     * The vocabulary of the stock screen — the kinds of movement and the
     * statuses a position can be in — published so a client builds its filters
     * from the server's answer rather than a hard-coded copy that drifts.
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'movement_types' => array_map(fn (StockMovementType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'required_sign' => $type->requiredSign(),
            ], StockMovementType::cases()),

            'statuses' => [
                ['value' => 'in_stock', 'label' => 'In stock'],
                ['value' => 'low', 'label' => 'At or below reorder level'],
                ['value' => 'out', 'label' => 'Out of stock'],
                // Named plainly. It is a data problem, not a shortage, and a
                // label that softened it would get ignored.
                ['value' => 'negative', 'label' => 'Negative — more issued than received'],
            ],
        ]);
    }

    /**
     * GET /api/v1/stock/variants/{variant}
     *
     * One variant's stock card: the position now, and every movement behind it
     * with the running balance after each.
     */
    public function card(StockCardRequest $request, int $variant): JsonResponse
    {
        $record = $this->variants->find($variant);
        $card = $this->stock->cardFor($record, $request->filters(), $request->perPage());

        $movements = array_map(function (array $row) use ($request) {
            return (new StockMovementResource($row['movement'], [
                'quantity' => $row['balance']->quantity->amount(),
                'value' => $row['balance']->value->amount(),
                'average_cost' => $row['balance']->averageCost()->amount(),
            ]))->resolve($request);
        }, $card['rows']);

        $page = $card['movements'];

        return ApiResponse::success([
            'position' => (new StockPositionResource($record, $this->stock->positionFor($record)))->resolve($request),
            'opening' => $card['opening']->toArray(),
            'closing' => $card['closing']->toArray(),
            'movements' => $movements,
        ], null, 200, [
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    /**
     * Whether the caller may see the money side of the reconciliation.
     *
     * Checked here rather than on the route because the *stock* half of this
     * response is legitimately theirs either way: a data-entry user needs to
     * know what is on the shelf, and gating the whole endpoint on LEDGER would
     * take that away to protect a figure that is one line of it.
     */
    private function canReadLedger(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $this->authorization->userHasPermission(
            $user,
            PermissionAction::Read->value,
            PermissionResource::Ledger->value,
        );
    }

    /**
     * Page an already-computed list.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(array $rows, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->input('page', 1));

        return new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }
}
