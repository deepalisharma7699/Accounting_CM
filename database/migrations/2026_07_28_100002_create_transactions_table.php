<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business event: "we sold a motor", "we paid the supplier", "we corrected
 * last month's mis-posting". Its accounting consequences live in
 * `journal_entries`, one row per debit or credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // Which posting template turns this into journal lines.
            $table->string('type', 30);

            // draft | posted | reversed. Journal entries exist only from
            // `posted` onwards — see TransactionStatus.
            $table->string('status', 20);

            // manual | import | ai. Provenance, for M12's worklists.
            $table->string('source', 20);

            // The date the event happened, which is not necessarily the date it
            // was entered — a bill captured on Monday may be dated Friday, and
            // every report is built on this one, never on created_at.
            $table->date('date');

            // Total debits, which for a posted transaction equals total
            // credits. Stored for listing and search only; every report derives
            // its numbers from the entries themselves.
            $table->decimal('total', 15, 2)->default(0);

            $table->string('notes', 500)->nullable();

            // A draft's intended lines, as [{account_id, debit, credit, memo}].
            //
            // Held here rather than as journal entries so that nothing in the
            // ledger has to filter drafts out: an unauthorised transaction is
            // simply absent from `journal_entries`. Nulled at the moment of
            // posting, so there is never a second copy of a posted line.
            $table->json('draft_lines')->nullable();

            // Who is accountable. Nullable so a user can be removed without
            // erasing the history of what they entered.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('posted_at')->nullable();

            // Set on the *reversing* transaction, pointing at the one it
            // cancels. The original keeps its entries and moves to `reversed`;
            // history is corrected by addition, never by deletion.
            $table->foreignId('reverses_id')->nullable()->constrained('transactions')->restrictOnDelete();

            $table->timestamps();

            // The day book, and the transaction list's default ordering.
            $table->index(['tenant_id', 'date', 'id']);

            // The draft worklist, and every status-filtered listing.
            $table->index(['tenant_id', 'status', 'date']);

            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
