<?php

namespace App\Support\Tenancy;

use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Tenant;
use Closure;

/**
 * Who "we" are for the duration of the current request, job or command.
 *
 * PostgreSQL would carry this in a session variable and let Row-Level
 * Security enforce it inside the database. On MySQL there is no such
 * mechanism, so this object plus {@see TenantScope} is the entire isolation
 * boundary — which is why it fails closed and why every deliberate bypass has
 * to name itself through {@see runWithoutScope()}.
 *
 * Registered as a singleton, so it is per-request in a normal PHP-FPM
 * lifecycle. Under a persistent worker (Octane, Horizon) the context outlives
 * the request, so anything long-running must set it explicitly — see
 * {@see runFor()} — rather than inheriting whatever the last job left behind.
 */
final class TenantContext
{
    private ?int $tenantId = null;

    /**
     * Distinguishes "deliberately operating with no tenant" (a platform
     * super-admin) from "nobody has established a tenant yet" (a bug). Both
     * leave $tenantId null; only the first is allowed to proceed.
     */
    private bool $resolved = false;

    /**
     * Depth rather than a flag, so nested runWithoutScope() calls do not let
     * the inner one re-enable scoping on the way out.
     */
    private int $unscopedDepth = 0;

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    public function current(): ?int
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * True once tenancy has been decided for this request — including the
     * decision that there is no tenant (a platform user).
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function isUnscoped(): bool
    {
        return $this->unscopedDepth > 0;
    }

    /**
     * The current tenant, or an explosion. Used where a tenant is structurally
     * required — writing a tenant-owned row, for instance.
     *
     * @throws MissingTenantContextException
     */
    public function requireTenant(string $context = 'this operation'): int
    {
        return $this->tenantId ?? throw MissingTenantContextException::for($context);
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * Establish the tenant for the rest of this request. Passing null marks
     * tenancy as resolved-to-nothing, which is what a platform super-admin is.
     */
    public function setTenant(Tenant|int|null $tenant): void
    {
        $this->tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;
        $this->resolved = true;
    }

    /**
     * Return to the unresolved state. Mainly for tests and long-lived workers
     * that must not leak one job's tenant into the next.
     */
    public function forget(): void
    {
        $this->tenantId = null;
        $this->resolved = false;
        $this->unscopedDepth = 0;
    }

    /* ---------------------------------------------------------------------
     | Scoped execution
     |-------------------------------------------------------------------- */

    /**
     * Run a callback as a given tenant, restoring the previous context
     * afterwards even if the callback throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant|int|null $tenant, Closure $callback): mixed
    {
        $previousId = $this->tenantId;
        $previousResolved = $this->resolved;

        $this->setTenant($tenant);

        try {
            return $callback();
        } finally {
            $this->tenantId = $previousId;
            $this->resolved = $previousResolved;
        }
    }

    /**
     * Run a callback with tenant scoping switched off.
     *
     * Every call site is a deliberate hole in the isolation boundary. There
     * are exactly four legitimate reasons to open one:
     *
     *   1. The authentication path, which resolves a user *in order to*
     *      establish tenancy and therefore runs before it exists.
     *   2. Platform super-admin operations over the tenants themselves.
     *   3. Seeders and migrations.
     *   4. Tests asserting what the scope actually hides.
     *
     * Anything else is a bug. `grep -rn runWithoutScope app/` is the audit.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runWithoutScope(Closure $callback): mixed
    {
        $this->unscopedDepth++;

        try {
            return $callback();
        } finally {
            $this->unscopedDepth--;
        }
    }
}
