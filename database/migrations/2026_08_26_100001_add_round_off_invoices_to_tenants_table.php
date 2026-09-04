<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the workshop rounds a bill to the nearest rupee.
 *
 * An 18% invoice lands on ₹1,062.36, and a counter holding a five-hundred-rupee
 * note does not find thirty-six paise. Rounding is what the trade does and what
 * CGST §170 permits; the difference is booked to the Round Off account so the
 * ledger still balances against what was actually charged.
 *
 * ## Off by default, including for existing workshops
 *
 * Unlike `allow_negative_stock`, there is no population to migrate: nothing has
 * ever rounded, so nothing is grandfathered. And unlike that setting, this one
 * changes **what a customer is charged**. A workshop that has been issuing exact
 * invoices for a year should not find its next one a rupee different because of
 * a deployment nobody told them about — so it stays off until somebody says
 * otherwise, and posted documents are untouched either way.
 *
 * ## Why a column and not a constant
 *
 * Because both answers are legitimate. A workshop billing corporate customers on
 * thirty-day terms settles by bank transfer to the paisa and wants the exact
 * figure; the one over the counter wants the rounded one. Choosing for them
 * would be choosing wrong for half of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('round_off_invoices')->default(false)->after('allow_negative_stock');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('round_off_invoices');
        });
    }
};
