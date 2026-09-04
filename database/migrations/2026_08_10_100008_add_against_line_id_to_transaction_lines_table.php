<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The invoice line a credit-note line is taking back — M18.
 *
 * ## Why the line and not just the bill
 *
 * Because "how much of this has already been returned?" has to be answerable per
 * line, and a bill very often carries the same variant twice: three bearings at
 * the list price on line 1, two more at a discount on line 4. Matching a return
 * to the *bill* and then to a variant would credit those at whichever price the
 * matcher happened to find first, and the customer would be refunded the wrong
 * amount.
 *
 * With this column the question is a sum over one integer key, and both refusals
 * the return service makes — more than was billed, and more than remains after
 * earlier returns — are exact rather than approximate.
 *
 * Null for every ordinary bill line, which is nearly all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lines', function (Blueprint $table) {
            // restrictOnDelete, like everything else that points at a posted
            // document: a bill line is never deleted, and a credit note that
            // lost the line it credits could not be checked against anything.
            $table->foreignId('against_line_id')
                ->nullable()
                ->after('line_no')
                ->constrained('transaction_lines')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('against_line_id');
        });
    }
};
