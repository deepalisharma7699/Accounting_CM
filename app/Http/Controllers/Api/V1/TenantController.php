<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\IndexTenantRequest;
use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Http\Requests\Tenant\UpdateTenantStatusRequest;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Services\Tenancy\TenantService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Platform administration of workshops.
 *
 * Every route here is guarded by the TENANTS permission, which only the ADMIN
 * system role holds. A workshop owner manages users and books inside their own
 * tenant; they never see this surface.
 */
class TenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
    ) {}

    /**
     * GET /api/v1/tenants
     */
    public function index(IndexTenantRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->tenants->paginate($request->filters(), $request->perPage()),
            TenantResource::class
        );
    }

    /**
     * GET /api/v1/tenants/{tenant}
     */
    public function show(int $tenant): JsonResponse
    {
        return ApiResponse::success(
            (new TenantResource($this->tenants->find($tenant)))->withUserCount()
        );
    }

    /**
     * POST /api/v1/tenants
     *
     * Creates the workshop, and its owner too when an `owner` block is
     * supplied — sales-led onboarding in one call.
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        $owner = $request->ownerPayload();

        if ($owner === null) {
            return ApiResponse::created(
                new TenantResource($this->tenants->provision($request->tenantPayload())),
                'Workspace created successfully.'
            );
        }

        ['tenant' => $tenant, 'owner' => $ownerUser] = $this->tenants
            ->provisionWithOwner($request->tenantPayload(), $owner);

        return ApiResponse::created(
            [
                'tenant' => (new TenantResource($tenant))->resolve(request()),
                'owner' => (new UserResource($ownerUser->load('customRole')))->resolve(request()),
            ],
            'Workspace and owner created successfully.'
        );
    }

    /**
     * PATCH /api/v1/tenants/{tenant}
     */
    public function update(UpdateTenantRequest $request, int $tenant): JsonResponse
    {
        return ApiResponse::success(
            new TenantResource($this->tenants->update($tenant, $request->payload())),
            'Workspace updated successfully.'
        );
    }

    /**
     * PUT /api/v1/tenants/{tenant}/status
     *
     * Suspending a workspace also ends every session inside it.
     */
    public function updateStatus(UpdateTenantStatusRequest $request, int $tenant): JsonResponse
    {
        return ApiResponse::success(
            new TenantResource($this->tenants->changeStatus($tenant, $request->status())),
            'Workspace status updated successfully.'
        );
    }

    /**
     * DELETE /api/v1/tenants/{tenant}
     */
    public function destroy(int $tenant): JsonResponse
    {
        $this->tenants->delete($tenant);

        return ApiResponse::message('Workspace deleted successfully.');
    }
}
