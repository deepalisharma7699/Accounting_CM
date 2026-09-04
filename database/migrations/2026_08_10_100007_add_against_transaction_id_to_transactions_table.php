<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bill a return is against — M18.
 *
 * ## Why this is not `reverses_id`
 *
 * They look alike and mean different things, and conflating them would break
 * both.
 *
 * `reverses_id` says *this document cancels that one whole*. The original moves
 * to `reversed`, its entries are mirrored line for line at their original
 * values, and nothing further can happen to it. That is the right tool for a
 * mis-posting.
 *
 * `against_transaction_id` says *some of what that document supplied has come
 * back*. The original stays **posted** — it was a real sale, it happened, and
 * three of its four bearings are still with the customer — and it can be
 * returned against again next week. A customer returning one of four bearings
 * has not cancelled the invoice, and a system that could only express
 * cancellation would make them do so.
 *
 * The two are therefore never both set. A reversal has no `against`, and a
 * return has no `reverses`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // restrictOnDelete, like `reverses_id`: a posted transaction is never
            // deleted, and quietly taking the bill a credit note refers to with it
            // would leave a credit against nothing.
            $table->foreignId('against_transaction_id')
                ->nullable()
                ->after('reverses_id')
                ->constrained('transactions')
                ->restrictOnDelete();

            // "What has been returned against this invoice" — the read behind the
            // over-return refusal, and behind the bill's own paid/due, which has
            // to know what has been credited back.
            $table->index(['tenant_id', 'against_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'against_transaction_id']);
            $table->dropConstrainedForeignId('against_transaction_id');
        });
    }
};
