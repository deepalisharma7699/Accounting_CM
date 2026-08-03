<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger itself — the only table in the product that records money moving.
 *
 * Every other number in the system is a query over this one: an account
 * ledger, a party's outstanding, the trial balance, the P&L. Nothing here is
 * ever updated or deleted, and rows only ever arrive through the posting
 * engine, in balanced sets, inside one database transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // restrictOnDelete, not cascade: a posted transaction is never
            // deleted, and if one ever were, taking its ledger lines with it
            // silently is exactly the failure this table exists to prevent.
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete();

            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            // Position within the voucher, so the lines read back in the order
            // they were written rather than in insertion-id order.
            $table->unsignedSmallInteger('line_no');

            // DECIMAL, never float. Exactly one of the two is non-zero, which
            // the CHECK constraint below enforces in the database itself.
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);

            // Copied from the transaction. A duplicate, but an immutable one —
            // a posted transaction's date can never change — and it turns every
            // ledger and every period report into a single-table indexed range
            // scan instead of a join.
            $table->date('date');

            $table->string('memo', 255)->nullable();

            // created_at only: an entry is written once and never touched
            // again, so an updated_at column would be a permanent lie.
            $table->timestamp('created_at')->nullable();

            // The ledger read: one account, in date order, over a period.
            $table->index(['tenant_id', 'account_id', 'date', 'id']);

            // The trial balance and the day book, which sweep every account.
            $table->index(['tenant_id', 'date']);

            $table->unique(['transaction_id', 'line_no']);
        });

        // The application refuses these before they are ever attempted — see
        // PostingEngine — but this module's whole premise is that a silent
        // corruption of the ledger is unrecoverable, so the rules are stated
        // once more where nothing can bypass them, including a future raw
        // query, an import script or a mistaken migration.
        //
        // MySQL only (8.0.16+, and this application is MySQL-only by design;
        // see tenancy-module.md). Skipped on any other driver rather than
        // failing, so the schema still builds.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_single_side
                 CHECK ((debit = 0) <> (credit = 0))'
            );

            DB::statement(
                'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_non_negative
                 CHECK (debit >= 0 AND credit >= 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
