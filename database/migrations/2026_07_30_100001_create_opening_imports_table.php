<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One go-live import: which file, when, by whom, and what it declared.
 *
 * **This table holds no balances.** Every figure on it is a copy of what the
 * ledger already says, kept because it is the record of a *decision* rather than
 * of a position — "we declared ₹4,12,000 of stock on 1 April from this file" is
 * not derivable from `journal_entries` afterwards, and it is exactly what
 * somebody re-reconciling a go-live six months later needs. The numbers here are
 * never read back as an authority; the trial balance is.
 *
 * ## Why a fingerprint and not a filename
 *
 * Because the failure this guards against is a browser refresh, a double click
 * or a retry after a timeout — and every one of those resubmits the same content
 * under the same name, while a genuinely corrected file usually arrives under
 * the same name too. Hashing the canonical rows means the check answers the
 * question actually being asked: "have these exact declarations already been
 * posted?"
 *
 * It is a second line of defence, not the first. The real protection against
 * doubling a balance is per-target: a variant that already has opening stock,
 * or a party that already has an opening transaction, is skipped whatever file
 * it arrives in — see OpeningBalanceService. That holds even when somebody
 * splits one workshop's opening position across three files, which a fingerprint
 * cannot see. The fingerprint exists so the commonest case gets an explanation
 * instead of a silent "0 rows imported".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_imports', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // What the workshop called it. Display only, and nullable because
            // the rows may have been typed into the screen rather than uploaded.
            $table->string('filename', 255)->nullable();

            // SHA-256 of the canonical rows. Unique per workshop, not globally:
            // two workshops opening with identical figures is a coincidence, not
            // a duplicate.
            $table->char('fingerprint', 64);

            // The date the declaration is made *as at* — normally the workshop's
            // books_start_date. Carried here as well as on the transactions so
            // the import reads as one act rather than as N unrelated postings.
            $table->date('date');

            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            // Rows whose target already had an opening balance. Recorded because
            // "12 of your 40 rows were already in" is the answer to the question
            // somebody asks when the totals do not match their spreadsheet.
            $table->unsignedInteger('skipped_count')->default(0);

            // What was declared, by kind. Copies of ledger figures, kept for the
            // reason above — a receipt for the decision, never a source of truth.
            $table->decimal('stock_value', 15, 2)->default(0);
            $table->decimal('receivable_total', 15, 2)->default(0);
            $table->decimal('payable_total', 15, 2)->default(0);
            $table->decimal('other_total', 15, 2)->default(0);

            // Catalogue and party records the import had to invent. Surfaced as
            // a worklist afterwards: an item created from a spreadsheet cell has
            // a name and nothing else, and somebody has to look at it.
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('parties_created')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The duplicate guard. Scoped to the tenant, as above.
            $table->unique(['tenant_id', 'fingerprint']);

            // The history list: most recent first.
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_imports');
    }
};
