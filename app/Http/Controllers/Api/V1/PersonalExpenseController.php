<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PersonalPaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Personal\IndexPersonalExpenseRequest;
use App\Http\Requests\Personal\StorePersonalExpenseRequest;
use App\Http\Resources\PersonalExpenseCategoryResource;
use App\Http\Resources\PersonalExpenseResource;
use App\Services\Personal\PersonalCategoryService;
use App\Services\Personal\PersonalExpenseService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner's personal diary — the Personal tab on the Money in & out card.
 *
 * Money out of the owner's own pocket, so nothing here posts: there is no
 * ledger entry, no stock, no tax and no report that reads it. Gated on
 * PERSONAL_EXPENSES, which only OWNER is given, and one diary per workshop.
 */
class PersonalExpenseController extends Controller
{
    public function __construct(
        private readonly PersonalExpenseService $expenses,
        private readonly PersonalCategoryService $categories,
    ) {}

    /**
     * GET /api/v1/personal-expenses/meta
     *
     * The categories (with counts) and the ways of paying — everything the form
     * draws, so none of it is written into markup.
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'categories' => PersonalExpenseCategoryResource::collection($this->categories->withCounts())
                ->resolve(request()),
            'methods' => array_map(
                fn (PersonalPaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()],
                PersonalPaymentMethod::cases(),
            ),
        ]);
    }

    /**
     * GET /api/v1/personal-expenses
     *
     * Newest first, with the filtered total and its split by category in
     * `meta.summary` — across every matching entry, not only this page.
     */
    public function index(IndexPersonalExpenseRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $page = $this->expenses->paginate($filters, $request->perPage());

        return ApiResponse::success(
            PersonalExpenseResource::collection(collect($page->items()))->resolve(request()),
            null,
            200,
            [
                'summary' => $this->expenses->summary($filters),
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

    public function show(int $expense): JsonResponse
    {
        return ApiResponse::success(new PersonalExpenseResource($this->expenses->find($expense)));
    }

    public function store(StorePersonalExpenseRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new PersonalExpenseResource($this->expenses->create($request->payload(), $this->authorId($request))),
            'Personal expense recorded.',
        );
    }

    public function update(StorePersonalExpenseRequest $request, int $expense): JsonResponse
    {
        return ApiResponse::success(
            new PersonalExpenseResource($this->expenses->update($expense, $request->payload())),
            'Personal expense updated.',
        );
    }

    public function destroy(int $expense): JsonResponse
    {
        $this->expenses->delete($expense);

        return ApiResponse::message('Personal expense deleted.');
    }

    private function authorId(Request $request): ?int
    {
        $id = $request->user()?->getKey();

        return $id === null ? null : (int) $id;
    }
}
