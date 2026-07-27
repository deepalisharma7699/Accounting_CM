<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Services\Rbac\PermissionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    /**
     * GET /api/v1/permissions
     *
     * `?grouped=true` returns the catalogue keyed by resource, which is what a
     * permissions-matrix UI needs.
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('grouped')) {
            return ApiResponse::success($this->permissions->groupedByResource());
        }

        return ApiResponse::success(
            PermissionResource::collection($this->permissions->list())
        );
    }
}
