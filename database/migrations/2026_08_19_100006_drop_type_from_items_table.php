<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove `items.type`, now that `items.category_id` answers the same question
 * better.
 *
 * ## Why this is a separate migration
 *
 * So that the backfill before it can be applied, inspected and reversed on its
 * own. Everything this column said is already recorded twice over: each item
 * points at a category, and the four seeded categories carry the enum's own
 * values as their `code`. Keeping the column as well would leave two answers to
 * "what kind of thing is this?", and the day they disagreed the catalogue would
 * be reporting one thing and behaving as another.
 *
 * ## Nothing is lost
 *
 * `type` was one of four fixed strings. Each is now a row in `item_categories`
 * with that string as its code, and `down()` puts the column back and refills it
 * from exactly that — so this reverses to the byte.
 *
 * What does *not* reverse is a category an admin created afterwards: "Water Pump"
 * has no `ItemType` to become, so an item filed under it gets `part` on the way
 * back. That is the only lossy part of the round trip, it only exists for
 * categories that could not have existed before, and it is stated here rather
 * than discovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The one thing worth checking before the column goes: nothing should
        // still be unfiled. The backfill sets every row, so this can only fire if
        // an item was written between the two migrations.
        $unfiled = DB::table('items')->whereNull('category_id')->count();

        if ($unfiled > 0) {
            throw new RuntimeException(
                "Cannot drop items.type: {$unfiled} item(s) have no category_id. ".
                'Re-run the catalogue backfill first — dropping the column now would '.
                'leave those rows with nothing at all to say what they are.'
            );
        }

        Schema::table('items', function (Blueprint $table) {
            // Named explicitly: the index covers `type`, so MySQL refuses to drop
            // the column while it stands. Its replacement — the category filter
            // and the stocked sweep — was added with `category_id`.
            $table->dropIndex(['tenant_id', 'type', 'is_stock']);
            $table->dropColumn('type');

            // What the dropped index served, minus the column: "every item this
            // workshop actually inventories", which is M8's per-variant sweep and
            // the low-stock view behind it. The category half of the question is
            // a join now, and `items_tenant_id_category_id_is_active_index`
            // covers that side.
            $table->index(['tenant_id', 'is_stock']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_stock']);

            // Nullable on the way back, then filled, then left nullable: the
            // column was NOT NULL originally, but restoring it as NOT NULL on a
            // table with rows in it would have to invent a value before the
            // backfill below could run.
            $table->string('type', 20)->nullable()->after('code');
        });

        // Refilled from the category's code, which is where it went.
        $categories = DB::table('item_categories')->whereNotNull('code')->get(['id', 'code']);

        foreach ($categories as $category) {
            DB::table('items')
                ->where('category_id', $category->id)
                ->update(['type' => $category->code]);
        }

        // Anything filed under a category an admin created after the change has
        // no enum value to go back to. `part` is the honest default: it is the
        // one type that carries no assumption beyond "a physical thing that is
        // bought and sold", which is true of every category that holds stock.
        DB::table('items')->whereNull('type')->update(['type' => 'part']);

        Schema::table('items', function (Blueprint $table) {
            $table->index(['tenant_id', 'type', 'is_stock']);
        });
    }
};
