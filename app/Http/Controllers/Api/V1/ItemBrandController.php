<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreBrandRequest;
use App\Http\Resources\ItemBrandResource;
use App\Services\Inventory\ItemBrandService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Brand Master — whose the shop's products are.
 *
 * Shaped exactly like {@see ItemCategoryController}, and deliberately: brand and
 * category are picked from the same form, one field apart, and a shop that
 * learned to add a category one way should not have to learn a second way to add
 * a brand. What is missing here is everything a *template* needs — there are no
 * fields under a brand, no inheritance, no defaults to copy — because a brand
 * decides nothing about the product beyond its name.
 *
 * ## Not paginated
 *
 * A shop has tens of brands, not thousands, and every screen that shows them
 * wants all of them: the master, the create form's dropdown, the list filter.
 * Paging a picker is how somebody ends up unable to find the brand they made a
 * minute ago.
 */
class ItemBrandController extends Controller
{
    public function __construct(
        private readonly ItemBrandService $brands,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * GET /api/v1/item-brands
     */
    public function index(Request $request): JsonResponse
    {
        $brands = $this->brands->all([
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
        ]);

        return ApiResponse::success(
            ItemBrandResource::collection($brands)->resolve($request),
        );
    }

    /**
     * GET /api/v1/item-brands/{brand}
     */
    public function show(int $brand): JsonResponse
    {
        $record = $this->brands->find($brand);

        return ApiResponse::success(
            new ItemBrandResource($record),
            null,
            200,
            ['usage' => $this->brands->usageFor($record)],
        );
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * POST /api/v1/item-brands
     */
    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = $this->brands->create($request->payload());

        return ApiResponse::created(
            new ItemBrandResource($brand),
            sprintf('"%s" is ready to use on the create form.', $brand->name),
        );
    }

    /**
     * PATCH /api/v1/item-brands/{brand}
     *
     * Also the archive control (`{"is_active": false}`), which is what a brand
     * products already carry gets instead of a delete.
     */
    public function update(StoreBrandRequest $request, int $brand): JsonResponse
    {
        $record = $this->brands->update($brand, $request->payload());

        return ApiResponse::success(
            new ItemBrandResource($record),
            'Brand updated.',
        );
    }

    /**
     * DELETE /api/v1/item-brands/{brand}
     *
     * Only ever reaches a brand no product carries. One that is in use is refused
     * and told to archive instead — deleting it would make unbranded things out
     * of every product filed under it, with nothing to say it happened.
     */
    public function destroy(int $brand): JsonResponse
    {
        $this->brands->delete($brand);

        return ApiResponse::message('Brand deleted.');
    }
}
