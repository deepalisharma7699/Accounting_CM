<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the people in a workshop are called: Fitter, Winder, Helper, Driver,
 * Accountant — M22.
 *
 * ## Why this is a table and not an enum
 *
 * Because the list is different in every workshop, and nobody can write it down
 * in advance. A rewinding shop has Winders and Varnishers; the pump dealer
 * across the road has Drivers and Loaders. An enum would mean a deployment every
 * time a workshop hired somebody they had not hired before — and, long before
 * that, it would mean somebody typing "Winder" into a Notes field because the
 * list did not have it.
 *
 * ## Why it is not free text on the employee either
 *
 * The same reason a brand is not free text on a product, and the catalogue
 * module already learned it the hard way: a typed designation is a master list
 * nobody maintains. Within a month there are three spellings of "Helper", the
 * filter offers all three, and none of them counts the whole trade. See
 * CLAUDE.md's note on the catalogue's vocabulary, which this follows exactly.
 *
 * Archived rather than deleted, like an account or a party: an employee filed
 * under "Apprentice" must keep the word that explains their pay grade even after
 * the workshop stops taking apprentices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_designations', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->string('name', 80);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One spelling per workshop. This index is the whole protection
            // against the drift described above.
            $table->unique(['tenant_id', 'name']);

            $table->index(['tenant_id', 'is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_designations');
    }
};
