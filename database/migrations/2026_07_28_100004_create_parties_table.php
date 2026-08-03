<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people and businesses a workshop trades with: customers, suppliers, and
 * the many who are both.
 *
 * There is no balance column here, and there never will be. What a party owes
 * — or is owed — is a sum over `journal_entries`, reached through the
 * transactions that carry their id. A stored outstanding drifts out of step
 * with the ledger the first time one is written without the other, and nobody
 * notices until a reconciliation months later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->string('name', 150);

            // ["customer"], ["vendor"], or both. A JSON array rather than a
            // pair of booleans because the roles are genuinely multi-value and
            // the set grows: M16 adds staff. A boolean per role would mean a
            // migration each time, and a `role` string column would force the
            // duplicate record this table exists to avoid — the rewinding shop
            // that sells you a motor and buys back your scrap is one party with
            // one ledger, not two half-ledgers that never reconcile.
            $table->json('roles');

            // Structure validated, checksum not — see Tenant::GSTIN_PATTERN.
            // Deliberately NOT unique: a chain with several branches files one
            // GSTIN across all of them, and refusing the second branch would
            // make the product wrong about a legitimate arrangement. Duplicates
            // are surfaced to the user instead of being prevented.
            $table->string('gstin', 15)->nullable();

            // First two digits of the GSTIN. Kept as its own column because M9
            // decides CGST+SGST versus IGST by comparing it with the workshop's,
            // and re-deriving that from a substring on every bill line is how
            // the comparison eventually gets written differently in two places.
            $table->string('state_code', 2)->nullable();

            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('notes', 500)->nullable();

            // Archived rather than deleted, exactly as with an account: a party
            // who has ever been transacted with must survive, or their ledger
            // lines lose the name that explains them.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One name, one party. Not merely tidiness: two rows called
            // "Sharma Traders" split one outstanding balance in half, and both
            // halves look plausible. Forcing the second to be named
            // distinctly — "Sharma Traders (Pune)" — is the whole protection.
            $table->unique(['tenant_id', 'name']);

            // The listing, which is almost always active-only and name-ordered.
            $table->index(['tenant_id', 'is_active', 'name']);

            // Finding the other branches that share a GSTIN.
            $table->index(['tenant_id', 'gstin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
