<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL means a platform user (super-admin) who sits above tenancy
            // and manages the tenants themselves. Every other user belongs to
            // exactly one tenant.
            //
            // restrictOnDelete, not cascade: deleting a tenant that still has
            // users must fail loudly rather than silently destroying accounts.
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            // Listing a tenant's users is the hottest tenant-filtered query in
            // the auth module; the FK's own index only covers tenant_id.
            $table->index(['tenant_id', 'status'], 'users_tenant_id_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_tenant_id_status_index');
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
