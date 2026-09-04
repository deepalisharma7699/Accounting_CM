<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The number a human refers to the transaction by — `INV/26-27/1001`.
 *
 * Until now a transaction had an id and nothing else, so "which invoice" could
 * only be answered with a database key. A customer ringing up about a bill quotes
 * the number printed on it, and every screen in M20 onwards is built around it.
 *
 * ## Why it is nullable
 *
 * Because a draft has no number. A number that could be discarded is a gap in the
 * series, and a gap is something somebody has to explain to an auditor —
 * "invoice 1004 does not exist" reads exactly like a suppressed sale. Numbers are
 * therefore assigned at the moment of posting and never before; see
 * {@see App\Services\Accounting\DocumentNumberService}.
 *
 * Nullable also carries the history: every transaction posted before this
 * migration ran has no number and correctly says so, rather than being
 * backfilled with a number nothing was ever printed with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('doc_no', 32)->nullable()->after('status');

            // Unique per workshop, not globally: two workshops both issuing
            // INV/26-27/1001 is correct — they are different businesses. MySQL
            // treats NULLs as distinct in a unique index, so any number of drafts
            // coexist under this constraint, which is exactly what is wanted.
            $table->unique(['tenant_id', 'doc_no']);

            // "Find INV/26-27/1012" — the lookup the bills list's search box
            // makes, and the one a customer's phone call starts with.
            $table->index(['tenant_id', 'doc_no']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'doc_no']);
            $table->dropIndex(['tenant_id', 'doc_no']);
            $table->dropColumn('doc_no');
        });
    }
};
