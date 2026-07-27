<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\AssignRoleRequest;
use App\Http\Requests\User\IndexUserRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Services\UserManagement\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    /**
     * GET /api/v1/users
     */
    public function index(IndexUserRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->users->paginate($request->filters(), $request->perPage()),
            UserResource::class
        );
    }

    /**
     * GET /api/v1/users/{user}
     */
    public function show(int $user): JsonResponse
    {
        return ApiResponse::success(
            (new UserResource($this->users->find($user)))->withPermissions()
        );
    }

    /**
     * POST /api/v1/users
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new UserResource($this->users->create($request->payload())),
            'User created successfully.'
        );
    }

    /**
     * PATCH /api/v1/users/{user}
     */
    public function update(UpdateUserRequest $request, int $user): JsonResponse
    {
        return ApiResponse::success(
            new UserResource($this->users->update($user, $request->payload())),
            'User updated successfully.'
        );
    }

    /**
     * PUT /api/v1/users/{user}/role
     */
    public function assignRole(AssignRoleRequest $request, int $user): JsonResponse
    {
        return ApiResponse::success(
            new UserResource($this->users->assignRole($user, $request->roleId())),
            'Role assigned successfully.'
        );
    }

    /**
     * PUT /api/v1/users/{user}/status
     */
    public function updateStatus(UpdateUserStatusRequest $request, int $user): JsonResponse
    {
        return ApiResponse::success(
            new UserResource($this->users->changeStatus($user, $request->status())),
            'User status updated successfully.'
        );
    }

    /**
     * DELETE /api/v1/users/{user}
     */
    public function destroy(Request $request, int $user): JsonResponse
    {
        $this->users->delete($user, $request->user());

        return ApiResponse::message('User deleted successfully.');
    }
}
