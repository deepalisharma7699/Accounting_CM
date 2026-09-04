<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What went into the motor — M19.
 *
 * One row per bearing, per metre of copper, per hour of rewinding labour. The
 * fitter adds them as the work happens, and the bill is generated from them at
 * the end.
 *
 * ## Adding a part here moves no stock. That is decision D2, and it is the
 * ## whole design of this table
 *
 * A row here is a **note about what will be billed**. It reserves nothing,
 * allocates nothing and does not touch `stock_movements`. The bearing leaves the
 * shelf when the invoice is posted, in exactly one movement, written by the same
 * posting engine that writes every other movement in the application.
 *
 * The alternative — issuing stock when a part is added to a job — is tempting
 * and wrong, and wrong in a way that takes months to notice. It would mean stock
 * could move without a posted transaction, which is the single invariant the
 * entire inventory module rests on: the Inventory account equals Σ(qty × cost)
 * *because* nothing writes a movement except a posting. Break it once, and the
 * stock ledger and the books drift apart with nothing to reconcile them by, and
 * the drift shows up as an unexplainable figure at a stock take.
 *
 * The visible cost of the decision is real and much smaller: a part written onto
 * a job is not yet subtracted from what the shelf shows, so two jobs can both
 * plan to use the last bearing. That is a conversation between two fitters, and
 * the refusal lands honestly at the moment either bill is posted — see M17's
 * `assertCanIssue()`.
 *
 * ## Why the price is stored here
 *
 * Because it is negotiated per job. "Call it two thousand for the pair" applies
 * to this motor and not to the catalogue, and re-reading `sell_price` when the
 * bill is generated would quietly put the list price on an invoice the customer
 * has already been quoted for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_job_parts', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // cascadeOnDelete, unlike the ledger's foreign keys — and legitimately,
            // because nothing here is in the books. A job that is deleted before it
            // was ever billed takes its shopping list with it; a job that *has*
            // been billed cannot be deleted at all, because its parts point at
            // transaction lines that restrict.
            $table->foreignId('workshop_job_id')->constrained('workshop_jobs')->cascadeOnDelete();

            // restrictOnDelete on both, exactly as a bill line does it: a part
            // whose item vanished loses the name that explains it, and "2 × 340"
            // is a number where "2 × Ball Bearing 6205 ZZ" is a record.
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();

            // Nullable, and for the same reason a bill line's is: an hour of
            // rewinding labour has nothing to vary. Anything stocked names one,
            // because stock is counted per variant.
            $table->foreignId('variant_id')->nullable()->constrained('item_variants')->restrictOnDelete();

            // The label as fitted, copied rather than joined — the same rule the
            // bill line follows, and for the same reason: renaming a variant next
            // year must not change what this job card says went into the motor.
            $table->string('description', 255);

            $table->decimal('quantity', 15, 3);
            $table->string('unit', 20);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);

            $table->string('memo', 255)->nullable();

            /*
            | The bill line this part became — written when the job is billed, and
            | never before.
            |
            | This is what makes "has this job been billed" a fact rather than a
            | flag somebody has to remember to set, and what stops a job being
            | billed twice: a part that already points at a line is not offered to
            | the next invoice. It is the same trick `stock_movements` plays with
            | its own `transaction_line_id`, for the same reason — a join beats a
            | duplicate, because a duplicate eventually disagrees.
            |
            | restrictOnDelete: a posted transaction is never deleted, and quietly
            | orphaning the link if one ever were would present as a job that could
            | be billed a second time.
            */
            $table->foreignId('transaction_line_id')->nullable()
                ->constrained('transaction_lines')->restrictOnDelete();

            $table->timestamps();

            // The job card itself: every part on this job, in the order they were
            // added.
            $table->index(['tenant_id', 'workshop_job_id']);

            // "Which jobs used this variant" — the read behind a part's own
            // history, and behind answering where the last four bearings went.
            $table->index(['tenant_id', 'variant_id']);
        });

        // Restating the service's rules where nothing can bypass them, exactly as
        // transaction_lines does.
        //
        // MySQL only (8.0.16+, and this application is MySQL-only by design).
        if (DB::getDriverName() === 'mysql') {
            // A part of nothing is not a part. Zero would sit on the job card
            // claiming something was fitted and bill for nothing.
            DB::statement(
                'ALTER TABLE workshop_job_parts ADD CONSTRAINT workshop_job_parts_quantity_positive
                 CHECK (quantity > 0)'
            );

            // Zero price is allowed — a part fitted free under warranty is a real
            // line, and leaving it off the job would take the stock without
            // recording why. Negative is not: a discount is the discount column.
            DB::statement(
                'ALTER TABLE workshop_job_parts ADD CONSTRAINT workshop_job_parts_amounts_non_negative
                 CHECK (unit_price >= 0 AND discount_amount >= 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_job_parts');
    }
};
