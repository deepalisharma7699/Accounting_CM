<?php

namespace App\Http\Middleware;

use App\Exceptions\Auth\AccountInactiveException;
use App\Exceptions\Auth\InvalidTokenException;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Auth\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * authGuard — verifies the bearer access token and binds the user to the
 * request.
 *
 * Registered as the `auth.jwt` alias.
 *
 * The user is re-read from the database on every request rather than trusted
 * from the token body: a 15-minute access token must not outlive a
 * deactivation or a role change by more than the request itself.
 */
class JwtAuthenticate
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->accessTokenFromRequest($request);

        if ($token === null) {
            throw InvalidTokenException::missing();
        }

        $claims = $this->tokens->verifyAccessToken($token);

        $user = $this->users->findById((int) $claims->sub);

        if ($user === null) {
            throw InvalidTokenException::revoked();
        }

        if (! $user->isActive()) {
            throw new AccountInactiveException($user->status);
        }

        // Both resolvers are set so `$request->user()`, `auth()->user()` and
        // route-model policies all see the same instance.
        $request->setUserResolver(fn () => $user);
        auth()->setUser($user);

        // Handy for logging / correlating a request with a specific token.
        $request->attributes->set('jwt_id', $claims->jti);

        return $next($request);
    }
}
