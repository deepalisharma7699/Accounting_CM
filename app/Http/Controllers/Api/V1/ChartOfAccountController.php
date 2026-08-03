<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\IndexAccountRequest;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\ChartOfAccountResource;
use App\Services\Accounting\ChartOfAccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * A workshop's chart of accounts.
 *
 * There is no destroy(). An account that has ever been posted to must survive,
 * or its journal entries lose their name and every historical report changes
 * retrospectively — so accounts are archived (`is_active: false`) instead.
 */
class ChartOfAccountController extends Controller
{
    public function __construct(
        private readonly ChartOfAccountService $accounts,
    ) {}

    /**
     * GET /api/v1/accounts
     */
    public function index(IndexAccountRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->accounts->paginate($request->filters(), $request->perPage()),
            ChartOfAccountResource::class
        );
    }

    /**
     * GET /api/v1/accounts/types
     *
     * The five account types with their code bands and normal balances —
     * everything a client needs to render the "new account" form without
     * hard-coding accounting rules of its own.
     */
    public function types(): JsonResponse
    {
        return ApiResponse::success(
            collect(AccountType::cases())->map(fn (AccountType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'normal_balance' => $type->normalBalance()->value,
                'is_balance_sheet' => $type->isBalanceSheet(),
                'code_range' => $type->codeRange(),
            ])->all()
        );
    }

    /**
     * GET /api/v1/accounts/{account}
     */
    public function show(int $account): JsonResponse
    {
        return ApiResponse::success(
            new ChartOfAccountResource($this->accounts->find($account))
        );
    }

    /**
     * POST /api/v1/accounts
     */
    public function store(StoreAccountRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new ChartOfAccountResource($this->accounts->create($request->payload())),
            'Account created successfully.'
        );
    }

    /**
     * PATCH /api/v1/accounts/{account}
     */
    public function update(UpdateAccountRequest $request, int $account): JsonResponse
    {
        return ApiResponse::success(
            new ChartOfAccountResource($this->accounts->update($account, $request->payload())),
            'Account updated successfully.'
        );
    }
}
