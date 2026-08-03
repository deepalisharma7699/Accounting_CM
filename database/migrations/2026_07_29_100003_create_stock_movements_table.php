<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stock ledger — and, in the same rows, the audit trail of every quantity
 * that ever moved.
 *
 * It stands in the same relation to inventory that `journal_entries` stands in
 * to money: **every stock number the product reports is a query over this
 * table.** Quantity on hand is `SUM(quantity)`, stock value is `SUM(value)`, and
 * the weighted average cost is the second divided by the first. There is no
 * `qty_on_hand` column and no `avg_cost` column anywhere in the schema, which is
 * what makes the roadmap's first invariant true by construction rather than by
 * discipline: they can only change through a movement because there is nothing
 * else to change.
 *
 * ## Why quantity and value are signed
 *
 * So that a position is one indexed sum. The alternative — a positive magnitude
 * plus a direction the caller decodes — means every read re-implements the same
 * CASE expression, and the first one that gets it wrong reports stock a workshop
 * does not have. The direction is the sign; the *reason* is `type`.
 *
 * ## Why `value` is authoritative and `unit_cost` is not
 *
 * `value` is what the Inventory account moved by, to the paise, and summing it
 * is what keeps the stock ledger and the money ledger equal. `unit_cost` is the
 * rate that movement was struck at — document detail, in exactly the sense
 * `transaction_payments.mode` is: useful on a voucher, never the source of a
 * total. An issue of 3 kg out of a 10 kg position worth ₹7,501 is valued at its
 * proportional share rather than at 3 × the rounded average, so a variant that
 * sells out leaves the Inventory account at exactly zero instead of a few paise
 * of stock nobody has.
 *
 * ## Why there is always a transaction
 *
 * `transaction_id` is NOT NULL. Stock moving is a business event with an
 * accounting consequence — a purchase, a sale, a write-off — and a movement
 * without one would be inventory value appearing or vanishing with nothing on
 * the other side of it. The rows are written by the posting engine inside the
 * *same* database transaction as the journal entries, which is what lets the
 * roadmap assert that the Inventory account equals Σ(qty × cost) across
 * variants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // restrictOnDelete, and NOT NULL: every movement belongs to a posted
            // business event, and a posted transaction is never deleted.
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete();

            // Stock is counted per *variant* — a 5 HP motor and a 7.5 HP motor
            // are not interchangeable and never share a balance. The item is
            // carried alongside it so a per-family report is one indexed sweep
            // rather than a join, and because it is the family that owns the unit
            // every quantity here is expressed in.
            //
            // restrictOnDelete on both. `item_variants.item_id` cascades from
            // `items`, so without the restraint here deleting a family would
            // silently take its stock history with it — the exact failure this
            // table exists to prevent. With it, the cascade is refused at the
            // database, behind the service's own check.
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('item_variants')->restrictOnDelete();

            // in | out | adjust | opening — see StockMovementType.
            $table->string('type', 20);

            // Signed, and three decimals because the trade weighs copper to the
            // gram. Whether a fraction is meaningful at all is the *unit's*
            // business — 2.5 kg is ordinary, 2.5 bearings is a mistake — and that
            // is checked in the service, where the unit is known.
            $table->decimal('quantity', 15, 3);

            // The rate this movement was struck at, per base unit. Never negative
            // regardless of the direction: the sign belongs to the quantity.
            $table->decimal('unit_cost', 15, 2)->default(0);

            // What the Inventory account moved by. Signed, and the authority on
            // stock value — see the note above.
            $table->decimal('value', 15, 2)->default(0);

            // Copied from the transaction, exactly as `journal_entries.date` is,
            // and immutable for the same reason: a posted transaction's date
            // cannot change, and it turns every period stock report into a
            // single-table indexed range scan instead of a join.
            $table->date('date');

            // Position within the transaction, so a bill's movements read back in
            // the order its lines were written.
            $table->unsignedSmallInteger('line_no');

            $table->string('memo', 255)->nullable();

            // created_at only. A movement is written once and never touched
            // again, so an updated_at column would be a permanent lie — the same
            // reasoning as a journal entry.
            $table->timestamp('created_at')->nullable();

            $table->unique(['transaction_id', 'line_no']);

            // The position read: everything for one variant, in the order it was
            // recorded. Ordered by id rather than by date on purpose — see
            // StockLedgerService on why the weighted average follows the order
            // costs became known rather than the order events are dated.
            $table->index(['tenant_id', 'variant_id', 'id']);

            // The per-variant movement history over a period, and the "as at"
            // valuation a stock report is built on.
            $table->index(['tenant_id', 'variant_id', 'date', 'id']);

            // The whole-workshop sweeps: the stock summary and the day's
            // movements.
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'item_id']);
        });

        // Restating in the database what the service already refuses, exactly as
        // journal_entries and transaction_payments do. This module's premise is
        // that stock and the Inventory account must agree for ever, and a raw
        // query, an import script or a mistaken migration must not be able to
        // write a row that breaks the arithmetic.
        //
        // MySQL only (8.0.16+, and this application is MySQL-only by design; see
        // tenancy-module.md). Skipped on any other driver rather than failing, so
        // the schema still builds.
        if (DB::getDriverName() === 'mysql') {
            // A movement of nothing is not a movement. It would sit in the
            // history claiming something happened and value at zero.
            DB::statement(
                'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_non_zero
                 CHECK (quantity <> 0)'
            );

            // The rate is a magnitude; the direction is the quantity's.
            DB::statement(
                'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_cost_non_negative
                 CHECK (unit_cost >= 0)'
            );

            // Value and quantity point the same way, or the movement claims stock
            // arrived while the Inventory account fell.
            DB::statement(
                'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_value_agrees
                 CHECK ((quantity > 0 AND value >= 0) OR (quantity < 0 AND value <= 0))'
            );

            // An `in` of minus three would read as an arrival and behave as an
            // issue. `adjust` is deliberately unconstrained: a stock-take
            // corrects in whichever direction the shelf disagrees.
            DB::statement(
                "ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_direction_matches_type
                 CHECK (
                     (type IN ('in', 'opening') AND quantity > 0)
                     OR (type = 'out' AND quantity < 0)
                     OR (type = 'adjust')
                 )"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
