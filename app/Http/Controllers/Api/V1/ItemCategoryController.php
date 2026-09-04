<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreAttributeRequest;
use App\Http\Requests\Catalogue\StoreCategoryRequest;
use App\Http\Resources\ItemAttributeResource;
use App\Http\Resources\ItemCategoryResource;
use App\Models\ItemAttribute;
use App\Services\Inventory\ItemAttributeService;
use App\Services\Inventory\ItemCategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Category Master, and the fields under each category.
 *
 * This is the surface that made the inventory generic. An admin creates a
 * category here, gives it fields, and the universal create form grows those
 * fields the next time it is opened — no migration, no new API, no new component,
 * no deployment.
 *
 * ## Why attributes are nested rather than a resource of their own
 *
 * A field has no meaning apart from its category. "Flow rate" is uninterpretable
 * without knowing it belongs to Water Pump, and the category is what decides
 * whether a key is already taken — by itself or by an ancestor it inherits from.
 * The nesting is enforced too: editing another category's field through this path
 * is a 404, not a silent success reported against the category the caller was
 * looking at.
 *
 * ## Not paginated
 *
 * A shop has tens of categories, not thousands, and every screen that shows them
 * wants all of them: the master, the create form's dropdown, the list filter.
 * Paging a picker is how somebody ends up unable to find the category they made a
 * minute ago.
 */
class ItemCategoryController extends Controller
{
    public function __construct(
        private readonly ItemCategoryService $categories,
        private readonly ItemAttributeService $attributes,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * GET /api/v1/item-categories
     */
    public function index(Request $request): JsonResponse
    {
        $categories = $this->categories->all([
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
        ]);

        return ApiResponse::success(
            ItemCategoryResource::collection($categories)->resolve($request),
            null,
            200,
            [
                // The ready-made definitions still worth offering — the ones
                // whose name is not already taken, so the picker never shows a
                // choice that would be refused.
                'templates' => array_map(fn (array $template) => [
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'field_count' => count($template['attributes']),
                    'fields' => array_map(
                        fn (array $field) => $field['label'],
                        $template['attributes'],
                    ),
                ], $this->categories->availableTemplates()),
            ],
        );
    }

    /**
     * GET /api/v1/item-categories/{category}
     *
     * With the whole inherited question set resolved, which is what the create
     * form draws and what the master screen edits — see
     * {@see ItemCategoryResource} on why both shapes are sent.
     */
    public function show(int $category): JsonResponse
    {
        $record = $this->categories->findWithSchema($category);

        return ApiResponse::success(
            new ItemCategoryResource($record->load(['fields', 'parent', 'children'])),
            null,
            200,
            ['usage' => $this->categories->usageFor($record)],
        );
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * POST /api/v1/item-categories
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($request->payload());

        return ApiResponse::created(
            new ItemCategoryResource($category->load(['fields', 'parent'])),
            'Category created. Add the fields it should ask for, and it is ready to use.',
        );
    }

    /**
     * POST /api/v1/item-categories/templates
     *
     * Create a category from one of the ready-made definitions — Bearing,
     * Capacitor, Wire, Water pump, LED light, Apparel.
     *
     * Offered rather than seeded: a garment shop should not find "Capacitor" in
     * its catalogue because the product was written for a motor workshop. What it
     * produces is an ordinary category the admin can rename, extend or delete a
     * minute later; nothing about it is privileged.
     */
    public function applyTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $category = $this->categories->applyTemplate($validated['name']);

        return ApiResponse::created(
            new ItemCategoryResource($category->load(['fields', 'parent'])),
            sprintf('"%s" is ready to use on the create form.', $category->name),
        );
    }

    /**
     * PATCH /api/v1/item-categories/{category}
     *
     * Also the archive control (`{"is_active": false}`), which is what a category
     * with products under it gets instead of a delete.
     */
    public function update(StoreCategoryRequest $request, int $category): JsonResponse
    {
        $record = $this->categories->update($category, $request->payload());

        return ApiResponse::success(
            new ItemCategoryResource($record->load(['fields', 'parent'])),
            'Category updated.',
        );
    }

    /**
     * DELETE /api/v1/item-categories/{category}
     *
     * Only ever reaches a category nothing depends on. One with products or
     * subcategories under it is refused and told to archive instead — deleting it
     * would leave them describing themselves in terms nothing defines.
     */
    public function destroy(int $category): JsonResponse
    {
        $this->categories->delete($category);

        return ApiResponse::message('Category deleted.');
    }

    /* ---------------------------------------------------------------------
     | Fields
     |-------------------------------------------------------------------- */

    /**
     * GET /api/v1/item-categories/{category}/attributes
     */
    public function attributes(Request $request, int $category): JsonResponse
    {
        $record = $this->categories->find($category);

        return ApiResponse::success(
            ItemAttributeResource::collection(
                $this->attributes->forCategory($record)
            )->resolve($request)
        );
    }

    /**
     * POST /api/v1/item-categories/{category}/attributes
     *
     * The endpoint behind the acceptance criterion: add "Lumens" here and the
     * universal form asks for lumens, with no other change anywhere.
     */
    public function storeAttribute(StoreAttributeRequest $request, int $category): JsonResponse
    {
        $record = $this->categories->find($category);

        $attribute = $this->attributes->create($record, $request->payload());

        return ApiResponse::created(
            new ItemAttributeResource($attribute),
            sprintf('"%s" will now appear on the create form for %s.', $attribute->label, $record->name),
        );
    }

    /**
     * PATCH /api/v1/item-categories/{category}/attributes/{attribute}
     *
     * Also the switch-off control (`{"is_active": false}`), which is what a field
     * products have answered gets instead of a delete: it stops appearing on the
     * form and goes on explaining the values already recorded under its key.
     */
    public function updateAttribute(StoreAttributeRequest $request, int $category, int $attribute): JsonResponse
    {
        $record = $this->categories->find($category);

        $updated = $this->attributes->update(
            $this->attributes->findForCategory($record, $attribute),
            $request->payload(),
        );

        return ApiResponse::success(
            new ItemAttributeResource($this->withUsage($updated)),
            'Field updated.',
        );
    }

    /**
     * DELETE /api/v1/item-categories/{category}/attributes/{attribute}
     */
    public function destroyAttribute(int $category, int $attribute): JsonResponse
    {
        $record = $this->categories->find($category);

        $this->attributes->delete($this->attributes->findForCategory($record, $attribute));

        return ApiResponse::message('Field deleted.');
    }

    /**
     * PUT /api/v1/item-categories/{category}/attributes/order
     *
     * The order the universal form draws the fields in, which matters more than
     * it looks: a specification reads the way somebody reciting it would say it —
     * 5 HP, 3 phase, 1440 RPM — and an alphabetical form makes every product take
     * a moment longer to read.
     */
    public function reorderAttributes(Request $request, int $category): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $record = $this->categories->find($category);

        return ApiResponse::success(
            ItemAttributeResource::collection(
                $this->attributes->reorder($record, $validated['ids'])
            )->resolve($request),
            'Field order saved.',
        );
    }

    /**
     * Hang the usage counts onto a field so the client can label its controls
     * before the server has to refuse anything.
     *
     * Two counting queries, so it is done for one record and never for a list.
     */
    private function withUsage(ItemAttribute $attribute): ItemAttribute
    {
        $attribute->usage = $this->attributes->usageFor($attribute);

        return $attribute;
    }
}
