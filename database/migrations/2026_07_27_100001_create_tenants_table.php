<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            // The workshop's trading name, as it should appear on documents.
            $table->string('name', 160);

            // Stable, human-readable handle. Useful for subdomains, support
            // tickets and log correlation without exposing the numeric id.
            $table->string('slug', 160)->unique();

            // GSTIN is issued once per state per PAN, so it is unique
            // nationally. Nullable: a workshop below the registration
            // threshold has none, and MySQL allows repeated NULLs in a
            // unique index.
            $table->string('gstin', 15)->nullable()->unique();

            $table->string('address', 500)->nullable();

            // Two-digit GST state code ("27" = Maharashtra). Drives
            // intra/inter-state tax treatment later, so it is stored on the
            // tenant from day one rather than derived from the GSTIN.
            $table->string('state_code', 2)->nullable();

            // active | suspended | cancelled. Anything other than active is
            // refused at login and by the auth guard, so suspending a tenant
            // locks out every one of its users on their next request.
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
