<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the workshop expects to pay for this variant — a reference figure, and
 * emphatically not a cost.
 *
 * The form has collected this since the universal create was written, validated
 * it, and carried it all the way into `ItemService::createWithVariant()` — where
 * it was read once as a fallback for the opening stock's unit cost and then
 * dropped on the floor. There was no column to put it in. A shop that typed its
 * buying rate found it again nowhere: not on the edit form, not in the drawer,
 * not on any payload.
 *
 * ## Why it is safe to store, and what makes it unsafe
 *
 * `sell_price` is what is charged, `markup_percent` is what was intended, and
 * neither is a margin — the cost is M8's weighted average at the moment of sale,
 * derived from `stock_movements`. This column joins that group: three numbers a
 * workshop writes down about a variant, none of which the books ever read.
 *
 * Two rules keep it that way, and both are the reason this is a column rather
 * than a costing field:
 *
 * * **It never reaches the ledger.** The cost of what is issued comes from the
 *   stock ledger and nowhere else (CLAUDE.md §4.3). A typed number feeding a
 *   valuation would put a figure in the books that nobody paid.
 * * **It never prefills a purchase line.** `docs/purchase-module.md` is explicit:
 *   stock arrives at the line's taxable value and that arrival recomputes the
 *   weighted average, so a rate suggested from here would quietly restate the
 *   shelf every time somebody tabbed past it.
 *
 * What it legitimately does is answer "what do we pay for this" on a screen, and
 * value opening stock for a variant that has never been purchased through this
 * product — which is the one moment a workshop has no weighted average to offer.
 *
 * Nullable, like every other price here. A variant bought at whatever the day's
 * rate is has no expected purchase price, and forcing a guess would be worse
 * than the blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_variants', function (Blueprint $table) {
            // Beside sell_price, because the pair is read together: what it costs
            // us and what we charge.
            $table->decimal('purchase_price', 15, 2)->nullable()->after('sell_price');
        });

        if (DB::getDriverName() === 'mysql') {
            // A separate named constraint rather than an extension of
            // `item_variants_non_negative`, because MySQL cannot extend a CHECK
            // in place — the same reason `min_stock` got one of its own in
            // 2026_08_19_100004. A negative buying price is not a rebate; it is a
            // number nobody meant to type.
            DB::statement(
                'ALTER TABLE item_variants ADD CONSTRAINT item_variants_purchase_price_non_negative
                 CHECK (purchase_price IS NULL OR purchase_price >= 0)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE item_variants DROP CONSTRAINT item_variants_purchase_price_non_negative');
        }

        Schema::table('item_variants', function (Blueprint $table) {
            $table->dropColumn('purchase_price');
        });
    }
};
