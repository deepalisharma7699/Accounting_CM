<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a bill may go unsettled before it counts as overdue.
 *
 * The one input the `overdue` payment status needs. Everything else about a
 * bill's status — paid, partial, unpaid — falls out of the arithmetic; overdue is
 * the only one that needs the workshop to say what its terms are, and a hard-coded
 * thirty days would be wrong for the workshop that sells for cash and wrong again
 * for the one that invoices a pump dealer monthly.
 *
 * Null means the workshop does not track it, and nothing is ever reported as
 * overdue — which is the honest answer for a counter trade where every bill is
 * settled on the spot. Defaulted to 30 because that is what most workshops
 * running an account actually work to, and a setting nobody has looked at yet
 * should still produce a useful ageing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_due_days')
                ->nullable()
                ->default(30)
                ->after('books_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('payment_due_days');
        });
    }
};
