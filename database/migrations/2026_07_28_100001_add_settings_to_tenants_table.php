<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // The month a financial year opens. India's runs April–March, so 4.
            // Only the month is stored: a financial year always starts on the
            // 1st, and storing a day would invite it being set to something
            // else.
            $table->unsignedTinyInteger('financial_year_start_month')
                ->default(4)
                ->after('state_code');

            // Transaction dates are entered and reported in the workshop's own
            // time. Stored as an identifier rather than an offset so DST and
            // future rule changes are the tz database's problem, not ours.
            $table->string('timezone', 64)->default('Asia/Kolkata')->after('financial_year_start_month');

            // Display formatting only. Not editable in Phase 1: GST, HSN/SAC
            // and the whole tax engine are India-specific, so a workshop set to
            // EUR would render currency symbols correctly and compute tax
            // wrongly.
            $table->string('currency', 3)->default('INR')->after('timezone');

            // Go-live date. Nothing may be posted before it — that period
            // belongs to whatever the workshop used previously, and its
            // closing position arrives as opening balances instead.
            // Nullable: set during onboarding, not at sign-up.
            $table->date('books_start_date')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'financial_year_start_month',
                'timezone',
                'currency',
                'books_start_date',
            ]);
        });
    }
};
