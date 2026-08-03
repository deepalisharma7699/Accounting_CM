<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
}
