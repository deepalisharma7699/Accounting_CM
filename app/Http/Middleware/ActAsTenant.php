<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a platform administrator operate *inside* one workshop's people, roles
 * and settings.
 *
 * Registered as `tenant.act`, and used on `/api/v1/tenants/{tenant}/…` routes
 * that are served by the very same controllers a workshop's own owner uses. The
 * only thing this changes is which workshop the request is *about*: it
 * re-points the tenant context, so every existing rule — users scoped to the
 * workshop, roles visible to it, an audit row written into its history — applies
 * exactly as it would to that workshop's owner. Nothing is reimplemented, so
 * nothing can drift.
 *
 * Three things keep it safe:
 *
 *   - Only a platform user (no workshop of their own) may. A workshop's owner
 *     naming another workshop's id is refused, not silently redirected.
 *   - The caller's own grants are still checked by the route's `permission`
 *     middleware; this changes *where* they apply, never *whether*.
 *   - It is deliberately narrow. It is put on the routes for users, roles,
 *     permissions and workspace settings — never on the books. A workshop's
 *     sales, stock and ledger stay its own.
 *
 * The `{tenant}` parameter is consumed here and removed from the route, because
 * Laravel passes route parameters to a controller by position: left in, it would
 * arrive as the first argument of `show(int $user)` and be read as a user id.
 */
class ActAsTenant
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->tenant_id !== null) {
            throw new ApiException(
                message: 'Only a platform administrator can act inside another workshop.',
                status: 403,
                errorCode: 'PLATFORM_ONLY',
            );
        }

        $id = (int) $request->route('tenant');

        // Tenant is not a tenant-owned model — it is what tenancy scopes *to* —
        // so this is an ordinary lookup, and a missing one is a plain 404.
        $tenant = Tenant::find($id) ?? throw new ResourceNotFoundException('Workshop', $id);

        $request->route()->forgetParameter('tenant');

        // For the rest of this request the workshop is `$tenant`. A platform
        // request is one request, so there is nothing to restore afterwards.
        $this->tenancy->setTenant($tenant);
        $request->attributes->set('acting_tenant_id', $tenant->id);

        return $next($request);
    }
}
