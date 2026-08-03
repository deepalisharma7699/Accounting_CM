<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a transaction was settled: which modes the money moved through, in what
 * amounts, and against what reference.
 *
 * ## Why this is not a stored balance
 *
 * The rule is that nothing derivable from `journal_entries` is stored, and this
 * table looks at first glance like it breaks it — ₹2,000 cash is already a line
 * on the Cash account. Two things it holds are genuinely not in the ledger:
 *
 *   * **the mode**, where the mapping is not one-to-one. A cheque and a bank
 *     transfer both settle through the Bank account, so the ledger cannot tell
 *     them apart afterwards. See {@see App\Enums\PaymentMode}.
 *   * **the reference** — a cheque number, a UPI transaction id. The thing
 *     somebody needs when a cheque bounces or a customer says they have paid.
 *
 * So this is document detail, in the same sense `transactions.total` is: a
 * record of how the event was described, not a second copy of its accounting
 * consequences. No report derives a number from it, and the settlement lines in
 * the ledger remain the authority on the money.
 *
 * The rows are written by the posting engine inside the *same* database
 * transaction as the journal entries, so a settlement can never exist without
 * the entries it describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // restrictOnDelete for the same reason as a journal entry: a posted
            // transaction is never deleted, and quietly taking its settlement
            // rows with it if one ever were is the failure mode to avoid.
            //
            // A draft has no rows here at all: its intended split lives in
            // `transactions.draft_payments`, exactly as its intended lines live
            // in `draft_lines`. Nothing outside the ledger has to remember to
            // exclude unauthorised work.
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete();

            // cash | bank | upi | cheque. The account each moves through is
            // decided by PaymentMode, never stored here — a workshop that
            // renumbers its chart must not orphan its payment history.
            $table->string('mode', 20);

            $table->decimal('amount', 15, 2);

            // Cheque number, UPI transaction id, NEFT reference. Required for a
            // cheque and optional otherwise — see PaymentMode::requiresReference().
            $table->string('reference', 100)->nullable();

            // The order the modes were entered in, so a voucher reads back the
            // way it was written.
            $table->unsignedSmallInteger('line_no');

            $table->timestamp('created_at')->nullable();

            $table->unique(['transaction_id', 'line_no']);

            // "Every cheque we wrote in March", "the day's UPI collections" —
            // the reads a reconciliation actually makes.
            $table->index(['tenant_id', 'mode']);
        });

        // Restating the engine's rules where nothing can bypass them, exactly as
        // journal_entries does. A settlement of zero moves nothing, and a
        // negative one is the opposite transaction written confusingly — the
        // type already carries the direction.
        //
        // MySQL only (8.0.16+, and this application is MySQL-only by design).
        // Skipped on any other driver rather than failing, so the schema still
        // builds.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE transaction_payments ADD CONSTRAINT transaction_payments_positive
                 CHECK (amount > 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_payments');
    }
};
