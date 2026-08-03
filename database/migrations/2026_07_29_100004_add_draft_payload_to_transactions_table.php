<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a draft was *asked for*, for the transaction types whose ledger lines are
 * derived rather than typed.
 *
 * `draft_lines` holds a journal's intended debits and credits, because that is
 * what the user actually wrote. A bill is different: nobody types "credit Sales
 * ₹4,237.29" — they type three motors at ₹1,666 and the template works the rest
 * out. Storing the *derived* lines for a draft bill would freeze two numbers
 * that must not be frozen:
 *
 *   * **the cost of goods sold**, which is the weighted average at the moment of
 *     posting and not at the moment somebody started the bill. A draft parked
 *     for a fortnight and posted after two deliveries would otherwise carry a
 *     margin computed against a price the workshop had stopped paying;
 *   * **the tax**, which follows a rate that a workshop may correct on the item
 *     before the draft is ever authorised.
 *
 * So this column holds the payload the template composes from, and a draft is
 * re-composed — re-priced, re-taxed and re-validated by today's rules — at the
 * moment it is posted. It is the same reasoning that sends `draft_lines` back
 * through `PostingLine::fromInput()` rather than trusting them because they were
 * saved once, carried to its conclusion.
 *
 * Deliberately generic rather than a `draft_bill_lines` column: M10's expense,
 * M11's opening balances and M15's captured drafts all need the same thing, and
 * a column per module would be four columns that are null on every row but one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Nulled at the moment of posting, in the same statement, exactly as
            // `draft_lines` and `draft_payments` are — so there is never a moment
            // when the request and the result both exist and could disagree.
            $table->json('draft_payload')->nullable()->after('draft_payments');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('draft_payload');
        });
    }
};
