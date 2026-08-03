<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only. Loaded exclusively under APP_ENV=testing — see
 * AppServiceProvider::loadTestOnlyMigrations().
 *
 * A stand-in for the tenant-owned tables that later slices add, so the
 * BelongsToTenant trait can be proven against a real table with real queries
 * before anything valuable depends on it. See TenantScopeTest.
 *
 * Dated far in the future so it always sorts last and never sits between two
 * application migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_scoped_fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_scoped_fixtures');
    }
};
