<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ItemCategory;
use App\Enums\OpeningRowKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Opening\OpeningBalanceRequest;
use App\Http\Resources\OpeningImportResource;
use App\Services\Onboarding\OpeningBalanceService;
use App\Services\Onboarding\OpeningCsvParser;
use App\Services\Onboarding\PlannedRow;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Getting a running workshop's existing position into the books — M11.
 *
 * Two verbs and they are deliberately not one. `preview` resolves a file and
 * writes nothing; `import` resolves the same file and commits it. Rolling them
 * into a single "import with a dry-run flag" would make the consequential act
 * something that happened because a boolean was left out — the same reasoning
 * that gave M4 a separate `post` route rather than a flag on the edit.
 */
class OpeningBalanceController extends Controller
{
    public function __construct(
        private readonly OpeningBalanceService $opening,
        private readonly OpeningCsvParser $parser,
    ) {}

    /**
     * GET /api/v1/opening-balances
     *
     * Where the workshop stands: whether anything has been declared, what
     * Opening Balance Equity holds, and whether the books reconcile.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            $this->opening->position(),
            null,
            200,
            ['history' => OpeningImportResource::collection($this->opening->history())->resolve(request())],
        );
    }

    /**
     * GET /api/v1/opening-balances/meta
     *
     * The vocabulary a client builds its grid and its file instructions from, so
     * neither carries a second copy of the rules that drifts.
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'kinds' => array_map(fn (OpeningRowKind $kind) => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'needs_party' => $kind->needsParty(),
                'party_role' => $kind->partyRole()?->value,
                // Null where the row decides — an account balance opens on
                // whichever side the account normally sits, unless it says
                // otherwise.
                'side' => $kind->side()?->value,
            ], OpeningRowKind::cases()),

            // Only the categories that can hold stock. Labour was never on a
            // shelf, so offering it here would be offering a row that can only
            // be refused.
            //
            // Read from the Category Master rather than from a fixed list, so a
            // shop that added "Water Pump" last week can import against it today.
            'item_types' => ItemCategory::query()
                ->active()
                ->where('holds_stock', true)
                ->with(['fields', 'parent.fields'])
                ->ordered()
                ->get()
                ->map(fn (ItemCategory $category) => [
                    // The code where there is one, so a file exported before the
                    // Category Master existed still imports; the name otherwise,
                    // because that is what the admin typed.
                    'value' => $category->code ?? $category->name,
                    'label' => $category->name,
                    'unit' => $category->default_unit_code ?? 'piece',
                    // What the `variant` column has to contain for a *new* item
                    // of this category, in the order the segments are read — the
                    // inverse of the label the app prints, which is what makes it
                    // explainable.
                    'variant_format' => implode(' / ', array_map(
                        fn (string $key) => strtolower($category->attributeSchema()[$key]['label'] ?? $key),
                        $category->requiredAttributeKeys(),
                    )),
                ])
                ->values()
                ->all(),

            'columns' => [
                'kind', 'name', 'variant', 'type', 'quantity',
                'unit_cost', 'amount', 'account', 'side', 'gstin', 'reference',
            ],
        ]);
    }

    /**
     * POST /api/v1/opening-balances/preview
     *
     * Resolve a declaration without writing a thing.
     *
     * The response is the same object the import commits, which is the point:
     * an owner who agrees to these figures gets these figures, because there is
     * no second rendering of them to disagree.
     */
    public function preview(OpeningBalanceRequest $request): JsonResponse
    {
        $plan = $this->opening->plan(
            $request->declarations($this->parser),
            $request->declaredOn(),
            $request->filename(),
        );

        return ApiResponse::success(
            array_map(fn (PlannedRow $row) => $row->toArray(), $plan->rows),
            null,
            200,
            ['summary' => $plan->summary()],
        );
    }

    /**
     * POST /api/v1/opening-balances
     *
     * Commit it. Everything — the catalogue records the rows needed, the party
     * records, every posting and the receipt — lands in one database
     * transaction or none of it does.
     */
    public function store(OpeningBalanceRequest $request): JsonResponse
    {
        $import = $this->opening->import(
            $request->declarations($this->parser),
            $request->declaredOn(),
            $request->filename(),
            $request->user(),
        );

        return ApiResponse::created(
            new OpeningImportResource($import->load('transactions', 'creator:id,name')),
            $import->imported_count === 0
                ? 'Everything in that file had already been declared, so nothing was posted again.'
                : sprintf('Opening balances posted — %d declaration(s).', $import->imported_count),
            // The position afterwards, on the same response that confirms the
            // import: the one thing somebody wants to see the instant they
            // commit is whether the books balance and whether the owner's stake
            // looks like what they know it to be.
            ['position' => $this->opening->position()],
        );
    }
}
