<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\IssuesSessions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\TokenService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use IssuesSessions;

    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
    ) {}

    /**
     * POST /api/v1/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        ['user' => $user, 'tokens' => $tokens] = $this->auth->register($request->payload(), $request);

        return $this->withRefreshCookie(
            ApiResponse::created(
                $this->sessionPayload($user, $tokens),
                'Workspace created successfully.'
            ),
            $tokens
        );
    }

    /**
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        ['user' => $user, 'tokens' => $tokens] = $this->auth->login($request->credentials(), $request);

        return $this->withRefreshCookie(
            ApiResponse::success($this->sessionPayload($user, $tokens), 'Signed in successfully.'),
            $tokens
        );
    }

    /**
     * POST /api/v1/auth/refresh
     *
     * Reads the refresh token from the HTTP-only cookie, rotates it, and
     * returns a new access token.
     */
    public function refresh(Request $request): JsonResponse
    {
        ['user' => $user, 'tokens' => $tokens] = $this->auth->refresh(
            $this->tokens->refreshTokenFromRequest($request),
            $request
        );

        return $this->withRefreshCookie(
            ApiResponse::success($this->sessionPayload($user, $tokens), 'Token refreshed.'),
            $tokens
        );
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revokes the presented refresh token and clears the cookie.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($this->tokens->refreshTokenFromRequest($request));

        return ApiResponse::message('Signed out successfully.')
            ->withCookie($this->tokens->forgetRefreshCookie());
    }

    /**
     * POST /api/v1/auth/logout-all — ends every session for the user.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $revoked = $this->auth->logoutEverywhere($user);

        return ApiResponse::success(
            ['sessions_revoked' => $revoked],
            'Signed out of all devices.'
        )->withCookie($this->tokens->forgetRefreshCookie());
    }

    /**
     * GET /api/v1/auth/me — the authenticated user plus effective permissions.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            (new UserResource($user->load(['customRole', 'tenant'])))->withPermissions()
        );
    }

    protected function tokenService(): TokenService
    {
        return $this->tokens;
    }
}
