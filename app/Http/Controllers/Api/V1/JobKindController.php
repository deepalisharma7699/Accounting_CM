<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreAttributeRequest;
use App\Http\Requests\JobKind\StoreJobKindRequest;
use App\Http\Requests\JobKind\UpdateJobKindRequest;
use App\Http\Resources\JobKindFieldResource;
use App\Http\Resources\JobKindResource;
use App\Models\JobKind;
use App\Services\Workshop\JobKindService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Kind Master — what a workshop takes in, and what it asks about each.
 *
 * The bench's own vocabulary, edited from the bench. It is deliberately **not**
 * the Category Master: what a workshop sells and what a customer wheels through
 * the door barely overlap, and the one list that served both offered Bearing
 * and Wire to the intake form while offering no cooler at all. The whole
 * argument is on {@see JobKind}.
 *
 * ## Gated on WORKSHOP_JOBS, not ITEMS
 *
 * Reading needs `READ:WORKSHOP_JOBS`, because the intake form cannot draw
 * without the list. Writing needs `UPDATE:WORKSHOP_JOBS` and deleting needs
 * `DELETE:WORKSHOP_JOBS` — the same split the Category Master makes between
 * adding a bearing and restructuring what every product records, applied to the
 * bench. A clerk who books motors in should not be able to change what the
 * workshop asks about every cooler.
 *
 * ## It reuses the catalogue's field request
 *
 * {@see StoreAttributeRequest} validates a *field definition*, and nothing it
 * checks is about what the field describes. A second copy would be a second set
 * of rules to drift (§5.1).
 */
class JobKindController extends Controller
{
    public function __construct(private readonly JobKindService $kinds) {}

    /**
     * GET /api/v1/job-kinds
     */
    public function index(Request $request): JsonResponse
    {
        $kinds = $this->kinds->all([
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'search' => $request->query('search'),
        ]);

        // One grouped query for the whole page rather than a count per row —
        // the list draws "3 jobs" on each, and the delete refusal needs it too.
        $counts = $this->kinds->jobCounts($kinds->pluck('id')->all());

        return ApiResponse::success(
            $kinds->map(fn (JobKind $kind) => new JobKindResource(
                $kind,
                $counts[(int) $kind->id] ?? 0,
            ))->all(),
            // Said out loud rather than inferred from an empty list: a workshop
            // whose schema step has not been run yet would otherwise be told it
            // has no kinds, which is a different thing from "this is not
            // switched on here yet" (§4.6).
            meta: ['installed' => $this->kinds->isInstalled()],
        );
    }

    /**
     * GET /api/v1/job-kinds/{kind}
     */
    public function show(int $kind): JsonResponse
    {
        $record = $this->kinds->find($kind);

        return ApiResponse::success(new JobKindResource(
            $record,
            $this->kinds->usageFor($record)['jobs'],
        ));
    }

    /**
     * POST /api/v1/job-kinds
     */
    public function store(StoreJobKindRequest $request): JsonResponse
    {
        $kind = $this->kinds->create($request->payload());

        return ApiResponse::created(
            new JobKindResource($this->kinds->find((int) $kind->id), 0),
            sprintf('"%s" added to what the workshop takes in.', $kind->name),
        );
    }

    /**
     * PATCH /api/v1/job-kinds/{kind}
     */
    public function update(UpdateJobKindRequest $request, int $kind): JsonResponse
    {
        $record = $this->kinds->update($kind, $request->payload());

        return ApiResponse::success(
            new JobKindResource($this->kinds->find((int) $record->id)),
            sprintf('"%s" saved.', $record->name),
        );
    }

    /**
     * DELETE /api/v1/job-kinds/{kind}
     */
    public function destroy(int $kind): JsonResponse
    {
        $this->kinds->delete($kind);

        return ApiResponse::success(null, 'Kind removed.');
    }

    /* ---------------------------------------------------------------------
     | The questions a kind asks
     |-------------------------------------------------------------------- */

    /**
     * GET /api/v1/job-kinds/{kind}/fields
     */
    public function fields(int $kind): JsonResponse
    {
        return ApiResponse::success(
            JobKindFieldResource::collection(
                $this->kinds->find($kind)->resolvedAttributes(false),
            ),
        );
    }

    /**
     * POST /api/v1/job-kinds/{kind}/fields
     */
    public function storeField(StoreAttributeRequest $request, int $kind): JsonResponse
    {
        $record = $this->kinds->find($kind);
        $field = $this->kinds->createField($record, $request->payload());

        return ApiResponse::created(
            new JobKindFieldResource($field),
            sprintf('The intake form now asks "%s" about a %s.', $field->label, $record->name),
        );
    }

    /**
     * PATCH /api/v1/job-kinds/{kind}/fields/{field}
     */
    public function updateField(StoreAttributeRequest $request, int $kind, int $field): JsonResponse
    {
        $record = $this->kinds->find($kind);

        return ApiResponse::success(
            new JobKindFieldResource(
                $this->kinds->updateField($record, $field, $request->payload()),
            ),
            'Field saved.',
        );
    }

    /**
     * PUT /api/v1/job-kinds/{kind}/fields/order
     */
    public function reorderFields(Request $request, int $kind): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $record = $this->kinds->find($kind);

        return ApiResponse::success(
            JobKindFieldResource::collection(
                $this->kinds->reorderFields($record, $request->input('ids', [])),
            ),
            'Order saved.',
        );
    }

    /**
     * DELETE /api/v1/job-kinds/{kind}/fields/{field}
     */
    public function destroyField(int $kind, int $field): JsonResponse
    {
        $this->kinds->deleteField($this->kinds->find($kind), $field);

        return ApiResponse::success(null, 'Field removed.');
    }
}
