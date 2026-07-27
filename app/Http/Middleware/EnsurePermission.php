<?php

namespace App\Http\Middleware;

use App\Exceptions\Auth\InsufficientPermissionsException;
use App\Exceptions\Auth\InvalidTokenException;
use App\Services\Rbac\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * permissionGuard — asserts the authenticated user holds the permission(s) a
 * route requires. Must run after {@see JwtAuthenticate}.
 *
 * Registered as the `permission` alias. Two equivalent call styles:
 *
 *   ->middleware('permission:WRITE,POSTS')            // single action/resource
 *   ->middleware('permission:READ:USERS,WRITE:USERS') // several, all required
 *
 * or, type-safe, via the helper:
 *
 *   ->middleware(EnsurePermission::using('WRITE', 'POSTS'))
 */
class EnsurePermission
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            // The route is misconfigured (permission without auth.jwt) or the
            // token was stripped; either way the client is unauthenticated.
            throw InvalidTokenException::missing();
        }

        $required = $this->parse($permissions);

        if (! $this->authorization->userHasAllPermissions($user, $required)) {
            throw new InsufficientPermissionsException(
                array_map(fn (array $pair) => $pair[0].':'.$pair[1], $required)
            );
        }

        return $next($request);
    }

    /**
     * Build the middleware string for a single permission — the readable
     * equivalent of `checkPermission('WRITE', 'POSTS')`.
     */
    public static function using(string $action, string $resource): string
    {
        return 'permission:'.strtoupper($action).':'.strtoupper($resource);
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array<int, array{0: string, 1: string}>
     */
    private function parse(array $permissions): array
    {
        if ($permissions === []) {
            throw new InvalidArgumentException('The permission middleware requires at least one permission.');
        }

        // "permission:WRITE,POSTS" — two bare segments are an action/resource
        // pair rather than two separate requirements.
        if (count($permissions) === 2
            && ! str_contains($permissions[0], ':')
            && ! str_contains($permissions[1], ':')) {
            return [[strtoupper($permissions[0]), strtoupper($permissions[1])]];
        }

        return array_map(function (string $permission) {
            if (! str_contains($permission, ':')) {
                throw new InvalidArgumentException(
                    "Malformed permission [{$permission}]. Expected ACTION:RESOURCE."
                );
            }

            [$action, $resource] = explode(':', $permission, 2);

            return [strtoupper(trim($action)), strtoupper(trim($resource))];
        }, $permissions);
    }
}
