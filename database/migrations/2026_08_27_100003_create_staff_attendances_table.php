<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who was in, on which day — M22.
 *
 * One row per person per day, and the unique index below is what makes that
 * true. Attendance is the input to payroll, so a second row for the same day is
 * not untidy data: it is a person paid twice, or paid for a day the sheet also
 * says they were absent, with no way to tell which mark was meant.
 *
 * ## Rows are the exception, not the rule
 *
 * There is deliberately **no row for an ordinary day**. A month of nine people
 * is 270 rows if every day is written and perhaps twenty if only the departures
 * from normal are — and the sheet is filled in by somebody standing at a bench,
 * not by a payroll clerk. So the absence of a row is meaningful, and what it
 * means depends on how the person is paid: a monthly salary is owed unless a
 * deduction is recorded, and a daily wage is earned only where a day is marked.
 * See {@see \App\Enums\SalaryBasis::unmarkedDayIsPaid()}, which is where that
 * decision lives and the only place it is made.
 *
 * That is also why marking a day is an **upsert**: the day sheet is opened,
 * corrected and saved again as often as somebody remembers something, and each
 * save must leave one row per person rather than another one.
 *
 * ## Why there is no overtime column
 *
 * Because converting hours into money needs a standard-hours-per-day figure that
 * no workshop in this trade agrees on, and a figure that is wrong pays every
 * overtime hour wrong for ever. Half a feature here is worse than none — the
 * same judgement the purchase module makes about landed cost. A workshop paying
 * for a Sunday marks the Sunday present rather than the week off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_attendances', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // restrictOnDelete, matching the rule the whole application follows:
            // an employee who has ever been marked is archived rather than
            // removed, or a posted payslip loses the person it explains.
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();

            $table->date('date');

            $table->string('status', 20);

            // Why the day was unusual — "went to the bank", "half day, dentist".
            // Short on purpose: this is a margin note, not a diary.
            $table->string('notes', 190)->nullable();

            $table->timestamps();

            // One mark per person per day. See the note above — this is a
            // correctness constraint, not tidiness.
            $table->unique(['tenant_id', 'employee_id', 'date']);

            // The day sheet: every mark for one date.
            $table->index(['tenant_id', 'date']);

            // No second index for "one person over a range of dates" — the
            // unique above is already an index on exactly those columns in
            // exactly that order, and the month register and the payroll read
            // both use it. A duplicate would be a write cost with no read to
            // pay for it.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendances');
    }
};
