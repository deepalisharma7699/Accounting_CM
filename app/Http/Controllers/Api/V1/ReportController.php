<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportPeriodRequest;
use App\Http\Resources\TransactionResource;
use App\Models\ChartOfAccount;
use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\ReportService;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Reading the books at every zoom level — M12.
 *
 * Nothing in this controller has numbers of its own. Every figure below is a sum
 * over `journal_entries`, `stock_movements` or `transaction_lines`, worked out
 * when it is asked for — see {@see ReportService}.
 *
 * Note what is *not* here. The trial balance is already `GET /ledger/trial-
 * balance`, the stock summary `GET /stock/summary`, the party statement
 * `GET /parties/{id}/ledger`, and the transaction list with its filters and its
 * drill-down `GET /transactions`. Re-exposing any of them under `/reports` would
 * be a second URL for one answer, and the second one is always the one that gets
 * out of step.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * GET /api/v1/reports/meta
     *
     * The periods a client may ask for, published rather than hard-coded so a
     * workshop's own financial year decides what "this year" means.
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'periods' => ReportPeriod::presets(),
            'stale_after_days' => ReportService::STALE_AFTER_DAYS,
        ]);
    }

    /**
     * GET /api/v1/reports/day-book
     *
     * Every voucher in the period, forwards, with its ledger lines — the oldest
     * report there is, and still the one somebody reaches for when a figure
     * looks wrong.
     */
    public function dayBook(ReportPeriodRequest $request): JsonResponse
    {
        $report = $this->reports->dayBook($request->period(), $request->perPage());
        $page = $report['transactions'];

        return ApiResponse::success(
            TransactionResource::collection(collect($page->items()))->resolve(request()),
            null,
            200,
            [
                'pagination' => $this->pagination($page),
                'period' => $report['period']->toArray(),
                // Over the whole period, not over this page: the figure a reader
                // compares against has to be the one they asked for.
                'totals' => [
                    'debit' => $report['totals']['debit']->amount(),
                    'credit' => $report['totals']['credit']->amount(),
                    'is_balanced' => $report['totals']['is_balanced'],
                ],
                'days' => array_map(fn (array $day) => [
                    'count' => $day['count'],
                    'total' => $day['total']->amount(),
                ], $report['days']),
            ],
        );
    }

    /**
     * GET /api/v1/reports/profit-and-loss
     *
     * Revenue, cost of sales and overheads — reported separately and never
     * netted, because a workshop with an 8% gross margin has a pricing problem
     * and one with a 40% margin that still loses money has a rent problem.
     */
    public function profitAndLoss(ReportPeriodRequest $request): JsonResponse
    {
        $report = $this->reports->profitAndLoss($request->period());

        return ApiResponse::success(
            [
                'income' => $this->section($report['income']),
                'cost_of_sales' => $this->section($report['cost_of_sales']),
                'overheads' => $this->section($report['overheads']),
            ],
            null,
            200,
            [
                'period' => $report['period']->toArray(),
                'totals' => array_map(fn ($amount) => $amount->amount(), $report['totals']),
            ],
        );
    }

    /**
     * GET /api/v1/reports/gst
     *
     * Output tax and input tax, rate by rate, read from the bill lines — the
     * only place in Phase 1 that knows the rate and the CGST/SGST/IGST split.
     */
    public function gst(ReportPeriodRequest $request): JsonResponse
    {
        $report = $this->reports->gstSummary($request->period());

        return ApiResponse::success(
            [
                'output' => $this->gstSide($report['output']),
                'input' => $this->gstSide($report['input']),
            ],
            null,
            200,
            [
                'period' => $report['period']->toArray(),
                'net_payable' => $report['net_payable']->amount(),
                // Stated even when it agrees. A difference means tax reached an
                // account with no bill behind it, and a reader has to be able to
                // tell "nothing to see" from "we did not check".
                'reconciliation' => [
                    'ledger_output' => $report['reconciliation']['ledger_output']->amount(),
                    'ledger_input' => $report['reconciliation']['ledger_input']->amount(),
                    'output_difference' => $report['reconciliation']['output_difference']->amount(),
                    'input_difference' => $report['reconciliation']['input_difference']->amount(),
                    'agrees' => $report['reconciliation']['agrees'],
                ],
            ],
        );
    }

    /**
     * GET /api/v1/reports/drafts
     *
     * Everything somebody started and never authorised, oldest first.
     * Deliberately not filtered by period: a draft is outstanding work rather
     * than an event, and the one from three months ago is the point.
     */
    public function drafts(ReportPeriodRequest $request): JsonResponse
    {
        $report = $this->reports->draftWorklist($request->perPage());

        return ApiResponse::success(
            array_map(fn (array $row) => [
                'transaction' => (new TransactionResource($row['transaction']))->resolve(request()),
                'age_in_days' => $row['age_in_days'],
                'is_stale' => $row['is_stale'],
                'reason' => $row['reason'],
            ], $report['rows']),
            null,
            200,
            [
                'pagination' => $this->pagination($report['transactions']),
                'totals' => [
                    'count' => $report['totals']['count'],
                    'stale' => $report['totals']['stale'],
                    'value' => $report['totals']['value']->amount(),
                ],
                'stale_after_days' => ReportService::STALE_AFTER_DAYS,
            ],
        );
    }

    /**
     * The page envelope, matching what {@see ApiResponse::paginated()} produces.
     *
     * Restated rather than reached for, because both reports here carry meta of
     * their own — a period, a set of totals — and `paginated()` owns the whole
     * meta key. One small duplication beats a helper that takes an array of
     * extras and is used twice.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array<string, mixed>
     */
    private function pagination(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
            'has_more' => $page->hasMorePages(),
        ];
    }

    /**
     * @param  array<int, array{account: ChartOfAccount, amount: Money}>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function section(array $rows): array
    {
        return array_map(fn (array $row) => [
            'account' => [
                'id' => $row['account']->id,
                'code' => $row['account']->code,
                'name' => $row['account']->name,
                'type' => $row['account']->type->value,
            ],
            'amount' => $row['amount']->amount(),
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $side
     * @return array<string, mixed>
     */
    private function gstSide(array $side): array
    {
        return [
            'taxable' => $side['taxable']->amount(),
            'cgst' => $side['cgst']->amount(),
            'sgst' => $side['sgst']->amount(),
            'igst' => $side['igst']->amount(),
            'tax' => $side['tax']->amount(),
            'rates' => array_map(fn (array $rate) => [
                'rate' => $rate['rate'],
                'taxable' => $rate['taxable']->amount(),
                'cgst' => $rate['cgst']->amount(),
                'sgst' => $rate['sgst']->amount(),
                'igst' => $rate['igst']->amount(),
                'tax' => $rate['tax']->amount(),
                'lines' => $rate['lines'],
            ], $side['rates']),
        ];
    }
}
