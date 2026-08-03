<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which import posted this transaction, where one did.
 *
 * `transactions.source` already says *how* a transaction came to exist — M11's
 * imports are `import`, M15's captures will be `ai` — and this says *which*.
 * They are different questions and the second is the one somebody asks when a
 * figure looks wrong: not "was this imported" but "was this the file we loaded
 * on the 3rd, the one with the wrong column order".
 *
 * Nullable and null for everything that was not imported, which is almost
 * everything. `nullOnDelete` rather than restrict: an import record is a receipt
 * for a decision, and losing the receipt must never be able to hold the postings
 * themselves hostage — they are in the books, and the books are the authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('opening_import_id')
                ->nullable()
                ->after('reverses_id')
                ->constrained('opening_imports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_import_id');
        });
    }
};
