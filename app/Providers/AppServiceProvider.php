<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadTestOnlyMigrations();
    }

    /**
     * Scaffolding tables that exist only under `php artisan test`.
     *
     * TenantScopeTest needs a real tenant-owned table to prove the
     * BelongsToTenant trait against, and no production table qualified when it
     * was written. Creating it inside the test was the obvious approach and
     * the wrong one: MySQL commits the open transaction on any DDL, which
     * destroys RefreshDatabase's rollback and leaves every subsequent
     * statement individually committed and fsynced — it cost roughly five
     * seconds per test.
     *
     * Registering the path here instead means `migrate:fresh` creates the
     * table once, before any transaction begins, and the tests stay inside
     * theirs.
     */
    private function loadTestOnlyMigrations(): void
    {
        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(base_path('tests/database/migrations'));
        }
    }
}
