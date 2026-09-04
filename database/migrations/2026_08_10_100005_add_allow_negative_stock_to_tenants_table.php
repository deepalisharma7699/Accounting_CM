<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the workshop may bill stock it does not have — M17, decision D6.
 *
 * ## This reverses a documented decision, deliberately
 *
 * `docs/inventory-module.md` argued for allowing negative stock and warning
 * about it: a fitter records Tuesday's sale before Friday's supplier invoice
 * arrives, and blocking Tuesday's sale does not produce the bearing — it
 * produces a workshop that stops recording sales. That reasoning is still
 * right for the workshop it describes.
 *
 * It is not right for the one the brief describes, which wants to be told
 * *"Only 5 PCS available in stock."* before it promises a customer a sixth. A
 * counter that will cheerfully sell what is not there is a counter whose stock
 * figures nobody trusts, and untrusted figures are the reason this module
 * exists.
 *
 * So the answer is a setting rather than a new absolute. Refused by default,
 * because that is the behaviour the brief asks for and the one that keeps the
 * shelf and the books together; permitted for any workshop that genuinely bills
 * ahead of its paperwork.
 *
 * ## Why existing workshops are switched on
 *
 * A tenant that already holds a negative position got there under the old rule,
 * and turning the refusal on underneath them would break the next bill they
 * write for a part they are mid-way through re-stocking. They are migrated to
 * the permissive setting and can turn it off when their positions are clean —
 * which is a decision for them, made deliberately, rather than one imposed by a
 * deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('allow_negative_stock')->default(false)->after('payment_due_days');
        });

        // Every workshop that is already carrying a negative position keeps the
        // old behaviour. Computed from the movements rather than from a stored
        // quantity, because there is no stored quantity — which is the property
        // the whole inventory module rests on.
        $negative = DB::table('stock_movements')
            ->selectRaw('tenant_id')
            ->groupBy('tenant_id', 'variant_id')
            ->havingRaw('SUM(quantity) < 0')
            ->pluck('tenant_id')
            ->unique()
            ->all();

        if ($negative !== []) {
            DB::table('tenants')->whereIn('id', $negative)->update(['allow_negative_stock' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('allow_negative_stock');
        });
    }
};
