<?php

namespace App\Http\Middleware;

use App\Exceptions\Auth\AccountInactiveException;
use App\Exceptions\Auth\InvalidTokenException;
use App\Exceptions\Tenancy\TenantInactiveException;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Auth\TokenService;
use App\Support\Tenancy\TenantContext;
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
 *
 * Tenancy is established here too, rather than in a middleware of its own.
 * Identity and tenancy are the same question in this application — which
 * workshop's books am I looking at is answered by who am I — and a separate
 * middleware could be left off a route, which on MySQL would mean an
 * unfiltered query. Doing it here makes that impossible: every authenticated
 * request has a tenant, and every unauthenticated one has no database access
 * to tenant-owned data at all.
 */
class JwtAuthenticate
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly UserRepositoryInterface $users,
        private readonly TenantContext $tenancy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->accessTokenFromRequest($request);

        if ($token === null) {
            throw InvalidTokenException::missing();
        }

        $claims = $this->tokens->verifyAccessToken($token);

        // Cross-tenant by necessity: this lookup is what decides the tenant.
        $user = $this->users->findAuthenticatable((int) $claims->sub);

        if ($user === null) {
            throw InvalidTokenException::revoked();
        }

        if (! $user->isActive()) {
            throw new AccountInactiveException($user->status);
        }

        if ($user->tenant !== null && ! $user->tenant->isActive()) {
            throw new TenantInactiveException($user->tenant->status);
        }

        // From here on every tenant-owned query is filtered. A platform
        // super-admin resolves to null, which is "no tenant" rather than
        // "tenant not yet known" — the difference between being allowed to
        // proceed and being refused.
        $this->tenancy->setTenant($user->tenant_id);

        // Both resolvers are set so `$request->user()`, `auth()->user()` and
        // route-model policies all see the same instance.
        $request->setUserResolver(fn () => $user);
        auth()->setUser($user);

        // Handy for logging / correlating a request with a specific token.
        $request->attributes->set('jwt_id', $claims->jti);
        $request->attributes->set('tenant_id', $user->tenant_id);

        return $next($request);
    }
}
