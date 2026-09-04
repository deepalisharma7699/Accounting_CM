<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Services\Inventory\UnitService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Unit Master: how the shop counts what it holds.
 *
 * Units used to be seven values in an enum, all of them from a rewinding
 * workshop. A capacitor is measured in µF, a pump in LPM, a bearing in MM and a
 * packet of chips in GRAM, and none of those could be added without a developer.
 * They are rows now.
 *
 * ## What this deliberately does not do
 *
 * **Conversion.** There is no purchase-unit-to-stock-unit factor here, and that
 * is a decision rather than an omission: every quantity in the ledger — every
 * stock movement, every bill line, every job part — is expressed in the item's
 * one unit, and a factor sitting between a purchase document and the stock ledger
 * corrupts stock and the Inventory account together, silently, if it is ever
 * wrong. The master comes first; conversion is its own piece of work with its own
 * testing.
 *
 * ## Not paginated
 *
 * Twenty-odd rows that every picker in the product needs in full.
 */
class UnitController extends Controller
{
    public function __construct(private readonly UnitService $units) {}

    /**
     * GET /api/v1/units
     *
     * `with_usage=1` adds what each unit is used by — products, posted document
     * lines and category fields. Four counting queries per unit, so the ordinary
     * listing does without it and the master screen, where somebody is deciding
     * what to remove, asks for it.
     */
    public function index(Request $request): JsonResponse
    {
        $units = $this->units->all([
            'search' => $request->input('search'),
            'kind' => $request->input('kind'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
        ]);

        if ($request->boolean('with_usage')) {
            $units->each(fn (Unit $unit) => $unit->usage = $this->units->usageFor($unit));
        }

        return ApiResponse::success(
            UnitResource::collection($units)->resolve($request)
        );
    }

    /**
     * GET /api/v1/units/{unit}
     */
    public function show(int $unit): JsonResponse
    {
        $record = $this->units->find($unit);
        $record->usage = $this->units->usageFor($record);

        return ApiResponse::success(new UnitResource($record));
    }

    /**
     * POST /api/v1/units
     */
    public function store(StoreUnitRequest $request): JsonResponse
    {
        $unit = $this->units->create($request->payload());

        return ApiResponse::created(new UnitResource($unit), 'Unit created.');
    }

    /**
     * PATCH /api/v1/units/{unit}
     *
     * Also the switch-off control (`{"is_active": false}`), which is what a unit
     * anything points at gets instead of a delete: it vanishes from the pickers
     * and goes on explaining the quantities already recorded in it.
     *
     * The code is never accepted. It is what every quantity ever recorded points
     * at, and changing it would reinterpret all of them at once.
     */
    public function update(StoreUnitRequest $request, int $unit): JsonResponse
    {
        $record = $this->units->update($unit, $request->payload());

        return ApiResponse::success(new UnitResource($record), 'Unit updated.');
    }

    /**
     * DELETE /api/v1/units/{unit}
     *
     * Only ever reaches a unit nothing points at, and never one of the seven the
     * system was set up with.
     */
    public function destroy(int $unit): JsonResponse
    {
        $this->units->delete($unit);

        return ApiResponse::message('Unit deleted.');
    }
}
