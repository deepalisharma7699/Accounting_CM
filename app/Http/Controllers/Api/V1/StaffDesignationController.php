<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreDesignationRequest;
use App\Http\Resources\StaffDesignationResource;
use App\Services\Staff\DesignationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The Designation Master — M22.
 *
 * The staff module's counterpart to the catalogue's brand and unit masters, and
 * it exists for the same reason those do: what the people in a workshop are
 * called is different in every workshop, nobody can write the list down in
 * advance, and a designation typed onto each employee is a master list nobody
 * maintains. Within a month there would be three spellings of "Helper", a filter
 * offering all three, and no single one of them counting the trade.
 *
 * Never rendered into a Blade template, for the same reason — see CLAUDE.md's
 * note on the catalogue's vocabulary. The Staff module draws this list from
 * `GET /api/v1/staff/meta`, and a copy in the markup would go stale the moment
 * an owner added one.
 *
 * No pagination: a workshop has a dozen of these and the form needs all of them.
 * A picker fed a page is a picker that silently cannot offer the thirteenth.
 */
class StaffDesignationController extends Controller
{
    public function __construct(
        private readonly DesignationService $designations,
    ) {}

    /**
     * GET /api/v1/staff/designations
     *
     * With a headcount against each, which is what makes "archive this" a
     * decision somebody can take: one nobody holds can go, one four people hold
     * cannot. One query for the whole list rather than one per row.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            StaffDesignationResource::collection($this->designations->withCounts())
        );
    }

    /**
     * POST /api/v1/staff/designations
     */
    public function store(StoreDesignationRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new StaffDesignationResource($this->designations->create($request->payload())),
            'Designation added.',
        );
    }

    /**
     * PATCH /api/v1/staff/designations/{designation}
     *
     * Also the archive control: `{"is_active": false}`. Archiving takes the word
     * out of every form while leaving everybody who already holds it untouched,
     * which is what retiring a designation actually means.
     */
    public function update(StoreDesignationRequest $request, int $designation): JsonResponse
    {
        return ApiResponse::success(
            new StaffDesignationResource($this->designations->update($designation, $request->payload())),
            'Designation updated.',
        );
    }

    /**
     * DELETE /api/v1/staff/designations/{designation}
     *
     * Only ever reaches one nobody holds. Anything in use is refused with
     * `DESIGNATION_IN_USE` and an explanation — the foreign key is
     * `nullOnDelete`, so the database would happily allow it and quietly blank
     * the trade off every employee who held it.
     */
    public function destroy(int $designation): JsonResponse
    {
        $this->designations->delete($designation);

        return ApiResponse::message('Designation deleted.');
    }
}
