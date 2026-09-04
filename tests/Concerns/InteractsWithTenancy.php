<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\ItemCategory;
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

    /**
     * The id of a seeded category, by the code the old `ItemType` enum used.
     *
     * Every workshop gets the four through {@see CatalogueProvisioner}, which
     * TenantFactory runs — so `categoryId('motor')` is the direct translation of
     * what `ItemType::Motor` used to be, and a test reads the same way it did.
     *
     * Resolved against the tenant in context where there is one, and against the
     * test's own `$tenant` otherwise: an API test builds its payload *outside* a
     * tenant context (the context is established by the request it is about to
     * make), and a helper that only worked inside one would return nothing there
     * and fail the request for a missing category.
     */
    protected function categoryId(string $code, ?Tenant $tenant = null): int
    {
        $tenantId = $tenant?->id
            ?? $this->tenantContext()->current()
            ?? (property_exists($this, 'tenant') ? $this->tenant?->id : null);

        return (int) ItemCategory::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->value('id');
    }
}
