<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();

            // NOT NULL, unlike users.tenant_id: this is tenant-owned data, and
            // a row belonging to nobody would be invisible to every tenant and
            // missing from every report. Enforced by
            // TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // Display and reporting number: 1010, 2100, 4000. Banded by type —
            // see AccountType::codeRange().
            $table->string('code', 10);

            $table->string('name', 120);
            $table->string('description', 255)->nullable();

            // asset | liability | equity | income | expense. Immutable once
            // set: changing it would silently reclassify every journal entry
            // already posted against the account.
            $table->string('type', 20);

            // The engine's handle on a seeded account (SystemAccount::value).
            // NULL for accounts a workshop adds itself. Deliberately separate
            // from `code` so a workshop could renumber its chart without
            // breaking a single posting template.
            $table->string('system_key', 40)->nullable();

            // Archived rather than deleted. An account that has ever been
            // posted to must remain, or its journal entries lose their name.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'system_key']);

            // Listing the chart grouped by type is the common read.
            $table->index(['tenant_id', 'type', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
