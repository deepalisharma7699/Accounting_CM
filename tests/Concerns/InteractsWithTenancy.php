<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Str;

/**
 * Companion to {@see InteractsWithAuthModule}, which supplies roleWith().
 * Use both together.
 */
trait InteractsWithTenancy
{
    /**
     * @param  array<int, array{0: string, 1: string}>  $grants
     */
    abstract protected function roleWith(array $grants, string $name = 'Test Role', bool $system = false): Role;

    protected function tenantContext(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * Act as a given tenant for the duration of a callback.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function actingForTenant(Tenant|int|null $tenant, Closure $callback): mixed
    {
        return $this->tenantContext()->runFor($tenant, $callback);
    }

    /**
     * Run a callback with tenant scoping disabled — used to assert what the
     * scope actually hid, which is the only way to prove isolation rather
     * than merely observing an empty result.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withoutTenantScope(Closure $callback): mixed
    {
        return $this->tenantContext()->runWithoutScope($callback);
    }

    /**
     * A workshop plus a user inside it holding the given grants.
     *
     * @param  array<int, array{0: string, 1: string}>  $grants
     * @return array{0: Tenant, 1: User}
     */
    protected function tenantWithUser(array $grants = [['*', '*']], ?string $roleName = null): array
    {
        $tenant = Tenant::factory()->create();

        // Role names carry a unique index, so distinct tenants in one test
        // need distinct role names.
        $role = $this->roleWith($grants, $roleName ?? 'Role '.Str::upper(Str::random(8)));

        $user = User::factory()->forTenant($tenant)->withRole($role)->create();

        return [$tenant, $user];
    }
}
