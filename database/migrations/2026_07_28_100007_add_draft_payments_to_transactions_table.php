<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A draft settlement's intended split, held on the transaction row.
 *
 * The exact counterpart of `draft_lines`, and for the same reason: a draft must
 * leave no trace anywhere the posted record lives, so that nothing querying
 * `transaction_payments` has to remember to filter unauthorised work out. An
 * unposted payment is simply absent from that table.
 *
 * Nulled in the same statement that posts, so the intended split and the written
 * rows never both exist and disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // [{mode, amount, reference}], in entry order.
            $table->json('draft_payments')->nullable()->after('draft_lines');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('draft_payments');
        });
    }
};
