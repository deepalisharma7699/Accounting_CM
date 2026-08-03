<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Item\IndexItemRequest;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\StoreVariantRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Http\Resources\ItemVariantResource;
use App\Models\Item;
use App\Services\Inventory\ItemService;
use App\Services\Inventory\ItemVariantService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;

/**
 * The catalogue: item families and the variants of each.
 *
 * Two things are worth noticing about the shape. There is **no stock anywhere** —
 * quantity on hand and average cost are M8's answer, derived from
 * `stock_movement`, and this module deliberately ships without a placeholder for
 * them so nobody builds against one.
 *
 * And variants are a nested resource rather than a top-level one. A variant has no
 * meaning apart from its family — "5 HP / 1440" is uninterpretable without knowing
 * it is a motor — and the family is what decides which attributes it must carry.
 */
class ItemController extends Controller
{
    public function __construct(
        private readonly ItemService $items,
        private readonly ItemVariantService $variants,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * GET /api/v1/items
     *
     * Variants are opt-in via `with_variants=1`, and cost one extra query for the
     * whole page rather than one per row. A picker asking for family names has no
     * use for them and does not pay for it.
     */
    public function index(IndexItemRequest $request): JsonResponse
    {
        $page = $this->items->paginate($request->filters(), $request->perPage());

        if ($request->wantsVariants()) {
            // One query for the whole page, not one per row. Loaded after the
            // pagination rather than eager-loaded in the repository, so a picker
            // that only wants names never pays for it.
            //
            // An Eloquent collection specifically: a plain one has no load().
            (new EloquentCollection($page->items()))->load('variants');
        }

        return ApiResponse::paginated($page, ItemResource::class);
    }

    /**
     * GET /api/v1/items/meta
     *
     * The vocabulary of the catalogue: the types, the attributes each is described
     * by, and the units. Published so a client builds its form from the server's
     * answer — an attribute schema copied into JavaScript is a copy that drifts,
     * and the drift shows up as a motor saved without its HP.
     *
     * The draft count comes along because every screen that shows the catalogue
     * wants the review-queue badge, and asking for it separately would be a second
     * round trip for one integer.
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'types' => array_map(fn (ItemType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'can_hold_stock' => $type->canHoldStock(),
                'default_uom' => $type->defaultUom()->value,
                'tax_code_label' => $type->usesSacCode() ? 'SAC' : 'HSN',
                // Keyed by attribute name, each with its label, whether it is
                // required, the fixed values where a fixed set exists, and the
                // unit suffix a form should print beside the box.
                'attributes' => $type->attributeSchema(),
            ], ItemType::cases()),

            'units' => array_map(fn (UnitOfMeasure $unit) => [
                'value' => $unit->value,
                'label' => $unit->label(),
                'symbol' => $unit->symbol(),
                'is_fractional' => $unit->isFractional(),
            ], UnitOfMeasure::cases()),

            'draft_counts' => [
                'items' => $this->items->draftCount(),
                'variants' => $this->variants->draftCount(),
            ],
        ]);
    }

    /**
     * GET /api/v1/items/{item}
     */
    public function show(int $item): JsonResponse
    {
        return ApiResponse::success(
            new ItemResource($this->items->findWithVariants($item))
        );
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * POST /api/v1/items
     */
    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->items->create($request->payload());

        return ApiResponse::created(
            new ItemResource($item->load('variants')),
            'Item created.'
        );
    }

    /**
     * PATCH /api/v1/items/{item}
     *
     * Also the archive control (`{"is_active": false}`) and the way a draft is
     * confirmed (`{"is_draft": false}`).
     */
    public function update(UpdateItemRequest $request, int $item): JsonResponse
    {
        $record = $this->items->update($item, $request->payload());

        return ApiResponse::success(
            new ItemResource($record->load('variants')),
            'Item updated.'
        );
    }

    /**
     * DELETE /api/v1/items/{item}
     *
     * Only ever reaches an item nothing points at. One with variants is refused
     * with `ITEM_IN_USE` and told to archive instead — deleting the family would
     * take its variants with it, which is work somebody loses without being asked.
     */
    public function destroy(int $item): JsonResponse
    {
        $this->items->delete($item);

        return ApiResponse::message('Item deleted.');
    }

    /* ---------------------------------------------------------------------
     | Variants
     |-------------------------------------------------------------------- */

    /**
     * GET /api/v1/items/{item}/variants
     */
    public function variants(int $item): JsonResponse
    {
        $record = $this->items->find($item);

        return ApiResponse::success(
            ItemVariantResource::collection($this->variants->forItem($record))->resolve(request())
        );
    }

    /**
     * POST /api/v1/items/{item}/variants
     *
     * The attributes are validated against the *item's* type here, not in the form
     * request — a motor needs its HP and a length of copper needs its gauge, and
     * only the family knows which.
     */
    public function storeVariant(StoreVariantRequest $request, int $item): JsonResponse
    {
        $record = $this->items->find($item);
        $payload = $request->payload();

        $variant = $this->variants->create($record, $payload);

        return ApiResponse::created(
            new ItemVariantResource($variant),
            'Variant added.',
            $this->duplicateWarnings($record, $payload['attributes'] ?? [], $variant->id),
        );
    }

    /**
     * PATCH /api/v1/items/{item}/variants/{variant}
     *
     * Nested under the item so the URL says what the variant belongs to, even
     * though the id alone would resolve it. Also the archive control.
     */
    public function updateVariant(StoreVariantRequest $request, int $item, int $variant): JsonResponse
    {
        $record = $this->items->find($item);
        $payload = $request->payload();

        // Resolved *through* the item, so the nesting in the URL means something:
        // editing another item's variant via this path is a 404, not a silent
        // success reported against the wrong family.
        $updated = $this->variants->update(
            $this->variants->findForItem($record, $variant),
            $payload,
        );

        return ApiResponse::success(
            new ItemVariantResource($updated),
            'Variant updated.',
            200,
            array_key_exists('attributes', $payload)
                ? $this->duplicateWarnings($record, $payload['attributes'], $updated->id)
                : [],
        );
    }

    /**
     * DELETE /api/v1/items/{item}/variants/{variant}
     */
    public function destroyVariant(int $item, int $variant): JsonResponse
    {
        $record = $this->items->find($item);

        $this->variants->delete($this->variants->findForItem($record, $variant));

        return ApiResponse::message('Variant deleted.');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    /**
     * A second variant with the same specification is reported, never refused.
     *
     * Two 5 HP / 1440 rows are usually the same motor entered twice, which splits
     * one stock balance in half — but a workshop stocking two brands at identical
     * ratings legitimately has two. So the duplicate is put in front of the user
     * while they can still merge them, exactly as a shared GSTIN is in M5.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function duplicateWarnings(Item $item, array $attributes, int $exceptId): array
    {
        $others = $this->variants->othersMatching($item, $attributes, $exceptId);

        if ($others->isEmpty()) {
            return [];
        }

        return [
            'warnings' => [[
                'code' => 'ITEM_VARIANT_DUPLICATE',
                'message' => sprintf(
                    'This specification is already on %s. That is correct for two brands at the same rating — '.
                    'if it is the same thing twice, use the existing variant so its stock stays in one place.',
                    $others->map(fn ($variant) => $variant->displayLabel())->join(', ', ' and '),
                ),
                'variant_ids' => $others->pluck('id')->all(),
            ]],
        ];
    }
}
