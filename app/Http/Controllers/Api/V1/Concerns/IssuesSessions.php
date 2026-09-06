<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\DataTransferObjects\TokenPair;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;

/**
 * What a started session looks like on the wire.
 *
 * Three endpoints start one — register, password login, passkey login — and
 * they must be indistinguishable to the client, because the client has exactly
 * one piece of code that consumes the answer. A fourth way in copying this
 * shape by hand is how one of them ends up returning the refresh token in the
 * body, or forgetting the cookie and leaving a session that dies at the first
 * refresh.
 */
trait IssuesSessions
{
    abstract protected function tokenService(): TokenService;

    /**
     * The body: who you are, what you may do, and an access token to do it
     * with.
     *
     * @return array<string, mixed>
     */
    protected function sessionPayload(User $user, TokenPair $tokens): array
    {
        return [
            'user' => (new UserResource($user->load(['customRole', 'tenant'])))
                ->withPermissions()
                ->resolve(request()),
            ...$tokens->toArray(),
        ];
    }

    /**
     * Attach the refresh token as an HTTP-only cookie — it is deliberately
     * absent from the JSON body so JavaScript can never read it.
     */
    protected function withRefreshCookie(JsonResponse $response, TokenPair $tokens): JsonResponse
    {
        if ($tokens->refreshToken === '') {
            return $response;
        }

        return $response->withCookie(
            $this->tokenService()->refreshCookie($tokens->refreshToken, $tokens->refreshTokenExpiresIn)
        );
    }
}
