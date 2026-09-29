<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Services\Rbac\PermissionService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TenantContext $context,
    ) {}

    /**
     * GET /api/v1/permissions
     *
     * `?grouped=true` returns the catalogue keyed by resource, which is what a
     * permissions-matrix UI needs.
     *
     * Inside a workshop this is the catalogue *narrowed to what that workshop's
     * caller may put into a role* — the matrix offers exactly what
     * RoleService will accept, so nobody is shown a tick that would be refused.
     * The platform sees all of it.
     */
    public function index(Request $request): JsonResponse
    {
        $permissions = $this->context->hasTenant()
            ? $this->permissions->grantableFor($request->user(), workshopRole: true)
            : $this->permissions->list();

        if ($request->boolean('grouped')) {
            return ApiResponse::success($this->permissions->groupedByResource($permissions));
        }

        return ApiResponse::success(PermissionResource::collection($permissions));
    }
}
