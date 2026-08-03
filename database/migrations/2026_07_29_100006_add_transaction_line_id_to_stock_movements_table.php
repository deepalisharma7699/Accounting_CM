<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which bill line a stock movement belongs to.
 *
 * The alternative was to copy the cost onto `transaction_lines` so a margin could
 * be read off one row. This is better for the reason every other choice in this
 * schema has gone the same way: **one number, in one place.** The movement's
 * `value` is already the authority on what the stock was worth — it is what the
 * Inventory account moved by — so a margin is
 *
 * ```
 * line.taxable_value  −  ABS(movement.value)
 * ```
 *
 * a join rather than a duplicate that could drift.
 *
 * Nullable, and it will stay nullable: a stock adjustment moves quantities with
 * no document line behind them at all, and M11's opening balances will too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // nullOnDelete rather than cascade or restrict. `transaction_lines`
            // cascades from `transactions`, which can only ever fire for a draft
            // — and a draft has no movements. If one somehow did, losing the link
            // is recoverable and losing the movement is not.
            $table->foreignId('transaction_line_id')
                ->nullable()
                ->after('variant_id')
                ->constrained('transaction_lines')
                ->nullOnDelete();

            // "The cost of every line on this bill" — one indexed read behind a
            // margin report.
            $table->index(['tenant_id', 'transaction_line_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['transaction_line_id']);
            $table->dropIndex(['tenant_id', 'transaction_line_id']);
            $table->dropColumn('transaction_line_id');
        });
    }
};
