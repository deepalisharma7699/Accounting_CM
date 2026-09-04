<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which invoice a receipt actually paid.
 *
 * Until now a receipt carried a `party_id` and nothing more, so the ledger could
 * say Rajesh Kumar owes ₹15,000 but not *which* of his three bills that ₹15,000
 * was left on. This table is the missing link: one row per (settlement, bill)
 * pair, carrying how much of the settlement went against that bill.
 *
 * ## Why this is not a stored balance
 *
 * The rule everywhere else in this schema is that nothing derivable from
 * `journal_entries` is stored, and a "paid" column on `transactions` would break
 * it outright. This does not, because the allocation is genuinely not in the
 * ledger: the receipt credits Sundry Debtors for the party, and no entry
 * anywhere records the operator's decision that the money was meant for invoice
 * 1012 rather than invoice 1001. That decision is a fact about the document, in
 * the same sense a cheque number is, and this is where it lives.
 *
 * What stays derived is everything computed *from* it. A bill's paid amount is
 * `SUM(amount)` over these rows plus its own at-counter payments, and its due is
 * total − paid — recomputed on every read, never written back to the bill. So a
 * reversed receipt or a corrected allocation moves the invoice's status without
 * anything having to remember to update it.
 *
 * ## Why a table rather than a nullable `against_transaction_id`
 *
 * Because one receipt commonly settles several invoices — a customer paying
 * ₹50,000 against four bills is one visit to the counter, not four receipts —
 * and one invoice is commonly settled by several receipts. A column on the
 * settlement could express neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_allocations', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // The receipt or payment doing the settling.
            //
            // restrictOnDelete for the same reason as a journal entry: a posted
            // transaction is never deleted, and quietly taking its allocations
            // with it if one ever were is the failure mode to avoid — every bill
            // it settled would silently become unpaid again.
            $table->foreignId('settlement_transaction_id')
                ->constrained('transactions')
                ->restrictOnDelete();

            // The sale or purchase being settled.
            $table->foreignId('bill_transaction_id')
                ->constrained('transactions')
                ->restrictOnDelete();

            $table->decimal('amount', 15, 2);

            $table->timestamps();

            // One row per pair. A receipt settling the same invoice twice is a
            // double-count, and there is no case for it: settling more of one
            // bill from one receipt is a larger `amount`, not a second row.
            $table->unique(['settlement_transaction_id', 'bill_transaction_id'], 'transaction_allocations_pair');

            // "What has been paid against this invoice" — the read behind every
            // Paid/Due column, every statement line and the outstanding filter.
            $table->index(['tenant_id', 'bill_transaction_id']);
        });

        // Restating the service's rules where nothing can bypass them, exactly as
        // transaction_payments does. A zero allocation settles nothing, and a
        // negative one is the opposite document written confusingly — a refund is
        // its own transaction, not a receipt with a minus sign.
        //
        // MySQL only (8.0.16+, and this application is MySQL-only by design).
        // Skipped on any other driver rather than failing, so the schema still
        // builds.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE transaction_allocations ADD CONSTRAINT transaction_allocations_positive
                 CHECK (amount > 0)'
            );

            // A document cannot settle itself. Cheap to state, and it forecloses
            // the one shape that would make a bill look paid by its own
            // existence.
            DB::statement(
                'ALTER TABLE transaction_allocations ADD CONSTRAINT transaction_allocations_distinct
                 CHECK (settlement_transaction_id <> bill_transaction_id)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_allocations');
    }
};
