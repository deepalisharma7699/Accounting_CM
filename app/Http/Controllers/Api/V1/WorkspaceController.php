<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\UpdateFavouriteModulesRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceRequest;
use App\Http\Resources\TenantResource;
use App\Services\Tenancy\TenantService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * A workshop owner's view of their *own* workshop.
 *
 * Deliberately has no `{id}` parameter. The workshop is resolved from the
 * tenant context that the auth guard established, so there is nothing in the
 * URL to tamper with and no way to reach another workshop whatever the client
 * sends. That is why this is a separate controller from TenantController
 * rather than a relaxed permission on it.
 *
 * Guarded by WORKSPACE, which the OWNER role holds and the platform ADMIN role
 * reaches only through its wildcard — and even then a platform user has no
 * tenant, so they get a clear 403 NO_WORKSPACE rather than an empty object.
 */
class WorkspaceController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
    ) {}

    /**
     * GET /api/v1/workspace
     */
    public function show(): JsonResponse
    {
        return ApiResponse::success(
            new TenantResource($this->tenants->currentWorkspace())
        );
    }

    /**
     * PATCH /api/v1/workspace
     */
    public function update(UpdateWorkspaceRequest $request): JsonResponse
    {
        return ApiResponse::success(
            new TenantResource($this->tenants->updateOwnWorkspace($request->payload())),
            'Workshop details updated successfully.'
        );
    }

    /**
     * PUT /api/v1/workspace/favourites
     *
     * Which module cards sit at the top of the workshop's home screen.
     *
     * PUT rather than PATCH because the body is the whole list — starring the
     * fourth card sends all four, and unstarring the last one sends an empty
     * array, which is a real answer rather than an omission. It is the reasoning
     * the settlement allocator already uses: a replacement is sent in full, so
     * there is no way for "nothing" to read as "unchanged".
     *
     * Its own route rather than a field on `update()` above, so that starring a
     * card does not announce that the ledger moved — see
     * UpdateFavouriteModulesRequest for the whole of why.
     */
    public function favourites(UpdateFavouriteModulesRequest $request): JsonResponse
    {
        return ApiResponse::success(
            new TenantResource($this->tenants->updateOwnWorkspace([
                'favourite_modules' => $request->favourites(),
            ])),
            'Home screen updated.'
        );
    }
}
