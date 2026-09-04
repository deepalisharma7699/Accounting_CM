<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The counter behind an invoice number.
 *
 * One row per (workshop, series, financial year), holding the next number that
 * series will issue. `INV` counts sales, `PUR` purchases, `RCT` receipts, and so
 * on — see {@see App\Enums\DocumentSeries}.
 *
 * ## Why a table rather than MAX(doc_no) + 1
 *
 * Because two clerks posting at the same moment would both read the same
 * maximum, both compute the same next number, and both write it — and a
 * duplicate invoice number is the one accounting error that cannot be corrected
 * by addition. A row that can be taken under `SELECT … FOR UPDATE` inside the
 * posting transaction makes the second poster wait for the first to commit,
 * which is the only arrangement where the number handed out is the number
 * stored. See {@see App\Services\Accounting\DocumentNumberService}.
 *
 * ## Why it resets per financial year
 *
 * Because the GST rules require a consecutive series unique within a financial
 * year, and every workshop's accountant expects the first invoice of April to be
 * number one. The year is part of the issued number rather than only of the
 * counter — `INV/26-27/1001` — so resetting cannot make last year's invoice
 * number ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // INV | PUR | RCT | PAY | EXP | JV | ADJ | OB. The prefix, and the
            // thing that makes two counters independent: a workshop's tenth
            // invoice and its third purchase bill are both "number 3" of nothing
            // in particular unless the series is part of the key.
            $table->string('series', 10);

            // "26-27" — the financial year the number belongs to, rendered the
            // way an Indian invoice renders it. A string rather than a start
            // date, because it is printed on the document as well as counted by.
            $table->string('financial_year', 9);

            // The number the *next* posting in this series will take. Stored as
            // the next rather than the last so a fresh series needs no special
            // case: the row is created at 1001 and handed out as it stands.
            //
            // Starts at 1001 rather than 1 so the first invoice a workshop
            // issues does not look like a test — and so the number's width does
            // not change under it at the hundredth bill.
            $table->unsignedInteger('next')->default(1001);

            $table->timestamps();

            // One counter per series per year, and the row the posting engine
            // locks. Unique rather than merely indexed because a second row for
            // the same series is a second counter, which is the failure this
            // whole table exists to prevent.
            $table->unique(['tenant_id', 'series', 'financial_year']);
        });

        // MySQL only (8.0.16+, and this application is MySQL-only by design).
        // Skipped on any other driver rather than failing, so the schema still
        // builds.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_positive
                 CHECK (next > 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
