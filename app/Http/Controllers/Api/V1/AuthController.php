<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\TokenPair;
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
                'Account created successfully.'
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
            (new UserResource($user->load('customRole')))->withPermissions()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(User $user, TokenPair $tokens): array
    {
        return [
            'user' => (new UserResource($user->load('customRole')))->withPermissions()->resolve(request()),
            ...$tokens->toArray(),
        ];
    }

    /**
     * Attach the refresh token as an HTTP-only cookie — it is deliberately
     * absent from the JSON body so JavaScript can never read it.
     */
    private function withRefreshCookie(JsonResponse $response, TokenPair $tokens): JsonResponse
    {
        if ($tokens->refreshToken === '') {
            return $response;
        }

        return $response->withCookie(
            $this->tokens->refreshCookie($tokens->refreshToken, $tokens->refreshTokenExpiresIn)
        );
    }
}
