<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Personal\StorePersonalCategoryRequest;
use App\Http\Resources\PersonalExpenseCategoryResource;
use App\Services\Personal\PersonalCategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The personal diary's category list. No pagination: a family has a dozen of
 * these, and a picker fed a page cannot offer the thirteenth.
 */
class PersonalExpenseCategoryController extends Controller
{
    public function __construct(
        private readonly PersonalCategoryService $categories,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            PersonalExpenseCategoryResource::collection($this->categories->withCounts())
        );
    }

    public function store(StorePersonalCategoryRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new PersonalExpenseCategoryResource($this->categories->create($request->payload())),
            'Category added.',
        );
    }

    /**
     * Also archive and restore: `{"is_active": false}`.
     */
    public function update(StorePersonalCategoryRequest $request, int $category): JsonResponse
    {
        return ApiResponse::success(
            new PersonalExpenseCategoryResource($this->categories->update($category, $request->payload())),
            'Category updated.',
        );
    }

    /**
     * Only one nothing is filed under — anything else is refused with
     * PERSONAL_CATEGORY_IN_USE and the advice to archive it.
     */
    public function destroy(int $category): JsonResponse
    {
        $this->categories->delete($category);

        return ApiResponse::message('Category deleted.');
    }
}
