<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The document: what was actually billed, line by line.
 *
 * Not the accounting — that is `journal_entries`, and it is derived from these.
 * A bill's ledger lines say "credit Sales ₹4,237.29"; this table says "three 5 HP
 * motors at ₹1,412.43 each, HSN 8501, 18% GST". One is what the books need and
 * the other is what the customer, the department and next year's argument need.
 *
 * ## Why the computed figures are stored
 *
 * `taxable_value`, the three tax columns and `line_total` are all derivable from
 * quantity, price and rate — and they are stored anyway. That is not a breach of
 * the no-stored-aggregates rule; it is the same reasoning that stores
 * `transaction_payments.mode`:
 *
 *   * **The tax on an invoice is fixed at the moment it is issued.** If a
 *     workshop corrects an item's GST rate next March, every invoice already sent
 *     must still say what it said. Recomputing on read would silently rewrite
 *     documents the customer is holding a copy of.
 *   * **The CGST/SGST/IGST split cannot be recovered from the ledger at all.**
 *     Phase 1 has one GST Output account, so the ledger carries the total; the
 *     three-way split lives here or nowhere.
 *
 * ## What is *not* stored here
 *
 * The **cost** of a line. It would be a second copy of the stock movement's
 * value, and the two would eventually disagree. Instead `stock_movements` carries
 * a `transaction_line_id`, so a line's cost — and therefore its margin — is a
 * join rather than a duplicate. See the migration that adds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_lines', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // cascadeOnDelete, unlike the ledger's foreign keys — and only
            // because a *draft* has no rows here at all. A posted transaction is
            // never deleted, so the cascade can only ever fire for something
            // that was never in the books.
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();

            // restrictOnDelete on both: a line whose item vanished loses the name
            // that explains it — "3 × 4,200" is a number, "3 × Copper Winding
            // Wire 22 SWG" is a record. The service refuses it first; this is
            // what catches everything that does not come through the service.
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();

            // Nullable, and legitimately: an hour of rewinding labour has nothing
            // to vary, so a service line names the item and no variant. A line
            // that moves stock always has one — the stock service refuses it
            // otherwise, because stock is counted per variant.
            $table->foreignId('variant_id')->nullable()->constrained('item_variants')->restrictOnDelete();

            $table->unsignedSmallInteger('line_no');

            // The label as billed, copied rather than joined. A workshop that
            // renames a variant next year must not change what last year's
            // invoice says it sold.
            $table->string('description', 255);

            // Positive. Whether a fraction is meaningful is the unit's business —
            // 2.5 kg is ordinary, 2.5 bearings is a mistake — and that is checked
            // where the unit is known.
            $table->decimal('quantity', 15, 3);

            // Copied for the same reason as the description: `each` must not
            // become `kilogram` on a document already issued.
            $table->string('unit', 20);

            $table->decimal('unit_price', 15, 2);

            // Per line, because that is where a discount is actually negotiated —
            // "call it two thousand for the pair" applies to the pair, not to the
            // bill. A document-level discount would have to be apportioned back
            // across lines to compute tax anyway.
            $table->decimal('discount_amount', 15, 2)->default(0);

            // quantity × price − discount. The base every tax figure is struck on.
            $table->decimal('taxable_value', 15, 2);

            // Copied from the item at the moment of billing — see above.
            $table->string('hsn_sac', 10)->nullable();
            $table->decimal('gst_rate', 5, 2)->default(0);

            // One of the two shapes, never a mixture: CGST+SGST within a state,
            // IGST across two. See GstBreakdown.
            $table->decimal('cgst_amount', 15, 2)->default(0);
            $table->decimal('sgst_amount', 15, 2)->default(0);
            $table->decimal('igst_amount', 15, 2)->default(0);

            $table->decimal('line_total', 15, 2);

            // Whether this line moved stock. Derived at the time from the item's
            // own flag and recorded, because the flag may be turned off later and
            // the question "did this bill take anything off the shelf" has to
            // stay answerable.
            $table->boolean('is_stock')->default(false);

            $table->string('memo', 255)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(['transaction_id', 'line_no']);

            // "Everything we have ever sold of this variant" — the read behind a
            // price history and behind M12's item movement report.
            $table->index(['tenant_id', 'variant_id']);
            $table->index(['tenant_id', 'item_id']);

            // The GST return: every line in a period, by rate.
            $table->index(['tenant_id', 'gst_rate']);
        });

        // Restating in the database what the bill engine already refuses, exactly
        // as journal_entries, transaction_payments and stock_movements do.
        //
        // MySQL only (8.0.16+, and this application is MySQL-only by design; see
        // tenancy-module.md). Skipped on any other driver rather than failing.
        if (DB::getDriverName() === 'mysql') {
            // A line of nothing is not a line. Zero quantity would sit on the
            // invoice claiming something was supplied and total nothing.
            DB::statement(
                'ALTER TABLE transaction_lines ADD CONSTRAINT transaction_lines_quantity_positive
                 CHECK (quantity > 0)'
            );

            // A negative price is not a discount — the discount column is the
            // discount column, and a negative price would flow into a total.
            DB::statement(
                'ALTER TABLE transaction_lines ADD CONSTRAINT transaction_lines_amounts_non_negative
                 CHECK (unit_price >= 0 AND discount_amount >= 0 AND taxable_value >= 0
                    AND cgst_amount >= 0 AND sgst_amount >= 0 AND igst_amount >= 0
                    AND line_total >= 0)'
            );

            // One shape or the other. A line carrying IGST *and* CGST is not a
            // supply anybody can classify, and the return would reject it.
            DB::statement(
                'ALTER TABLE transaction_lines ADD CONSTRAINT transaction_lines_one_tax_shape
                 CHECK (igst_amount = 0 OR (cgst_amount = 0 AND sgst_amount = 0))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_lines');
    }
};
