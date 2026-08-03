<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ledger\LedgerRequest;
use App\Http\Resources\ChartOfAccountResource;
use App\Http\Resources\JournalEntryResource;
use App\Services\Accounting\ChartOfAccountService;
use App\Services\Accounting\LedgerService;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

/**
 * Reading the books: one account's ledger, and the trial balance over all of
 * them.
 *
 * Both are queries over `journal_entries` — no report in this product has
 * numbers of its own, which is what guarantees that a ledger, a trial balance
 * and a P&L can never disagree about the same period.
 */
class LedgerController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccountService $accounts,
    ) {}

    /**
     * GET /api/v1/ledger/accounts/{account}
     *
     * An account's entries in date order, each with the running balance after
     * it, plus the balance the page opens and closes on.
     */
    public function account(LedgerRequest $request, int $account): JsonResponse
    {
        $result = $this->ledger->forAccount(
            $this->accounts->find($account),
            $request->period(),
            $request->perPage(),
        );

        $page = $result['entries'];

        return ApiResponse::success(
            JournalEntryResource::collection(collect($page->items()))->resolve(request()),
            null,
            200,
            [
                'account' => (new ChartOfAccountResource($result['account']))->resolve(request()),
                'opening_balance' => $result['opening']->amount(),
                'closing_balance' => $result['closing']->amount(),
                'normal_balance' => $result['normal_balance']->value,
                'period' => [
                    'from' => $request->input('from'),
                    'to' => $request->input('to'),
                    'debit' => $result['period']['debit']->amount(),
                    'credit' => $result['period']['credit']->amount(),
                ],
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
     * GET /api/v1/ledger/trial-balance
     *
     * Every account that has been posted to, and the proof that the books
     * balance. A workshop with no transactions reconciles at 0 = 0 — an empty
     * result, not an error.
     */
    public function trialBalance(LedgerRequest $request): JsonResponse
    {
        $period = $request->period();
        $result = $this->ledger->trialBalance($period['from'], $period['to']);

        $rows = array_map(fn (array $row) => [
            'account' => [
                'id' => $row['account']->id,
                'code' => $row['account']->code,
                'name' => $row['account']->name,
                'type' => $row['account']->type->value,
                'type_label' => $row['account']->type->label(),
                'normal_balance' => $row['account']->normalBalance()->value,
                'is_active' => $row['account']->is_active,
            ],
            'debit' => $row['debit']->amount(),
            'credit' => $row['credit']->amount(),
            'balance' => $row['balance']->amount(),
            'balance_side' => $row['balance_side']->value,
            'signed_balance' => $row['signed_balance']->amount(),
        ], $result['rows']);

        return ApiResponse::success($rows, null, 200, [
            'period' => $period,
            // Gross movements: every debit ever written against every credit.
            'totals' => [
                'debit' => $result['totals']['debit']->amount(),
                'credit' => $result['totals']['credit']->amount(),
            ],
            // The same thing after each account is collapsed onto one side,
            // which is the form a printed trial balance takes.
            'balances' => [
                'debit' => $result['balances']['debit']->amount(),
                'credit' => $result['balances']['credit']->amount(),
            ],
            'is_balanced' => $result['is_balanced'],
            'difference' => $result['difference']->amount(),
        ]);
    }

    /**
     * GET /api/v1/ledger/summary
     *
     * The one-line health check: total debits, total credits, and whether they
     * agree. Cheap enough to put on a dashboard.
     */
    public function summary(LedgerRequest $request): JsonResponse
    {
        $period = $request->period();
        $totals = $this->ledger->totals($period['from'], $period['to']);

        return ApiResponse::success([
            'debit' => $totals['debit']->amount(),
            'credit' => $totals['credit']->amount(),
            'is_balanced' => $totals['is_balanced'],
            'difference' => Money::of($totals['debit'])->minus(Money::of($totals['credit']))->amount(),
            'period' => $period,
        ]);
    }
}
