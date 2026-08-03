<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which party a transaction was with.
 *
 * Deferred from M4 rather than left sitting nullable and unconstrained until
 * the table it points at existed — see ledger-module.md. It arrives here with
 * its foreign key, which is the only version of this column worth having.
 *
 * Nullable, and legitimately so: a depreciation journal, a correcting entry and
 * a cash expense have no counterparty. What makes a party ledger correct is not
 * that every transaction has one, but that every transaction touching a control
 * account does — which each posting template from M6 onwards guarantees by
 * construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // restrictOnDelete rather than nullOnDelete: quietly detaching a
            // party from the transactions that reference them would empty their
            // ledger while leaving the money in the books. PartyService refuses
            // the delete first, with an explanation; this is the backstop for
            // anything that does not go through it.
            $table->foreignId('party_id')
                ->nullable()
                ->after('source')
                ->constrained('parties')
                ->restrictOnDelete();

            // One party's transactions in date order — the outer half of the
            // party ledger, and the drill-down behind every outstanding figure.
            $table->index(['tenant_id', 'party_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['party_id']);
            $table->dropIndex(['tenant_id', 'party_id', 'date']);
            $table->dropColumn('party_id');
        });
    }
};
