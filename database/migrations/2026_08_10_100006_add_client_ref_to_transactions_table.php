<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's own name for the document it is trying to create — M17, and the
 * brief's §28.
 *
 * A counter clerk taps **Save** and the network stalls. They tap it again. Two
 * requests arrive, both valid, and the workshop has billed the customer twice —
 * which is not a bug anybody can see until the customer notices, and by then the
 * stock has moved twice as well.
 *
 * The fix is that the *client* decides what "the same bill" means. It generates
 * a UUID once, when the operator starts writing the document, and sends it with
 * every attempt. The second attempt matches the first and gets the first
 * transaction back, with a 200 rather than a 201 — so a retry after a timeout is
 * safe, and so is a double tap.
 *
 * ## Why not an idempotency-key table
 *
 * Because the answer being looked up *is* a transaction, so a second table would
 * only hold a pointer to this one — and a pointer that can be written and then
 * fail to be followed is another thing that can go wrong. A unique index on the
 * row itself cannot get out of step with the row.
 *
 * ## Why it is nullable
 *
 * Everything already in the books predates it, and every server-side caller —
 * M11's importer, a seeder — legitimately has no client to name it. MySQL treats
 * NULLs as distinct in a unique index, so any number of them coexist, which is
 * exactly what is wanted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('client_ref')->nullable()->after('doc_no');

            // Per workshop, not global: two workshops' clients generating the
            // same UUID is impossible in practice and would be somebody else's
            // problem if it happened.
            $table->unique(['tenant_id', 'client_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'client_ref']);
            $table->dropColumn('client_ref');
        });
    }
};
