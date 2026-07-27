<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\SyncPermissionsRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\Rbac\RoleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
    ) {}

    /**
     * GET /api/v1/roles
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'system' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return ApiResponse::paginated(
            $this->roles->paginate(
                array_filter(
                    ['search' => $validated['search'] ?? null, 'system' => $validated['system'] ?? null],
                    fn ($value) => $value !== null
                ),
                (int) ($validated['per_page'] ?? 15)
            ),
            RoleResource::class
        );
    }

    /**
     * GET /api/v1/roles/{role}
     */
    public function show(int $role): JsonResponse
    {
        return ApiResponse::success(new RoleResource($this->roles->find($role)));
    }

    /**
     * POST /api/v1/roles
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new RoleResource($this->roles->create($request->payload())),
            'Role created successfully.'
        );
    }

    /**
     * PATCH /api/v1/roles/{role}
     */
    public function update(UpdateRoleRequest $request, int $role): JsonResponse
    {
        return ApiResponse::success(
            new RoleResource($this->roles->update($role, $request->payload())),
            'Role updated successfully.'
        );
    }

    /**
     * DELETE /api/v1/roles/{role}
     */
    public function destroy(int $role): JsonResponse
    {
        $this->roles->delete($role);

        return ApiResponse::message('Role deleted successfully.');
    }

    /**
     * PUT /api/v1/roles/{role}/permissions — replaces the role's grants.
     */
    public function syncPermissions(SyncPermissionsRequest $request, int $role): JsonResponse
    {
        return ApiResponse::success(
            new RoleResource($this->roles->syncPermissions($role, $request->permissionIds())),
            'Role permissions updated successfully.'
        );
    }
}
