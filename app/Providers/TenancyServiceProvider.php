<?php

namespace App\Providers;

use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Eloquent\EloquentTenantRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

/**
 * Wires multi-tenancy: the request-scoped tenant context and the tenant
 * repository binding.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton, so the scope, the repositories and the middleware all see
        // the same context object within a request. Everything that reads
        // tenancy resolves it from the container rather than holding a static,
        // which keeps it swappable in tests.
        $this->app->singleton(TenantContext::class);

        $this->app->bind(TenantRepositoryInterface::class, EloquentTenantRepository::class);
    }
}
