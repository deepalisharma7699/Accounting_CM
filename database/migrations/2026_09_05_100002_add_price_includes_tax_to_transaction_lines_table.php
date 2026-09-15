<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this line's `unit_price` already had the GST in it.
 *
 * Snapshotted for the same reason `gst_rate` and `hsn_sac` beside it are, but
 * the failure it prevents is a different one. The other two would merely go
 * stale; this one is **unrecoverable**. ₹100 at 18% quoted before tax and ₹118
 * at 18% quoted with it are the same `taxable_value`, the same `cgst_amount`
 * and the same `line_total` — the only thing that differs is `unit_price`, and
 * nothing can tell from ₹100 or ₹118 alone which of the two somebody typed.
 *
 * Two things have to reproduce a line rather than merely read it, and both would
 * be wrong without this column. `ReturnService` restates an invoice line as a
 * credit note from its price, its discount and its rate — extracting tax where
 * the invoice added it would credit back ₹18 of tax the customer was never
 * charged. And `components/bill-revision.js` loads a posted document back into
 * the create form to correct it, where the toggle has to come up as it was set
 * or the correction restates the whole document at a different total.
 *
 * False for every line already posted, which is exactly what they meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lines', function (Blueprint $table) {
            $table->boolean('price_includes_tax')->default(false)->after('gst_rate');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lines', function (Blueprint $table) {
            $table->dropColumn('price_includes_tax');
        });
    }
};
