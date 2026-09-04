<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One month's payroll, as it was actually paid — M22.
 *
 * ## A run is a fact, not a work in progress
 *
 * There is no draft status here, and that is the design. A parked payroll sheet
 * is a set of figures derived from an attendance register that keeps moving
 * under it: somebody would open a fortnight-old draft, see the total, post it,
 * and pay a month that three subsequent absences had already made wrong — with
 * the stale figure looking exactly as authoritative as a fresh one. So the sheet
 * is computed on demand, checked on screen, and either posted or abandoned. This
 * row is written at the moment it posts.
 *
 * That is why there is no discard endpoint and nothing to clean up. The only
 * transition is posted to reversed, and reversing is what frees the month to be
 * run again against the attendance as it now stands.
 *
 * ## Why the month is not unique
 *
 * It very nearly is — a workshop has one payroll per month, and running two
 * would pay everybody twice. But a *reversed* run must not block its own
 * replacement, which is the whole point of reversing it, and MySQL has no
 * partial unique index to express "one live run per month". So the rule lives in
 * {@see \App\Services\Staff\PayrollService::assertMonthIsFree()} where it can say
 * which run is in the way and what to do about it, and the index below is for
 * reading rather than for guarding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            /*
            | The month this run pays for, stored as its first day.
            |
            | A date rather than a year and a month integer, so every comparison,
            | range and sort is the ordinary one the database already does well,
            | and so "September 2026" cannot be stored as month 9 of year 26.
            |
            | Distinct from `transactions.date`, which is the day the money moved.
            | A run for August paid on 7 September is dated 7 September in the
            | ledger and 1 August here, and both are right: the expense belongs to
            | the month it was earned in, the cash left on the day it left.
            */
            $table->date('period_month');

            $table->string('status', 20);

            $table->string('notes', 500)->nullable();

            /*
            | The voucher this run posted.
            |
            | Nullable only because the column has to exist before the row does —
            | it is set inside the same database transaction as the posting, and a
            | run without one is not a state anything can reach. restrictOnDelete
            | because a posted transaction is never deleted anyway, and if that
            | ever changed, silently orphaning a payslip from the money it
            | represents is the last thing that should happen quietly.
            */
            $table->foreignId('transaction_id')->nullable()
                ->constrained('transactions')->restrictOnDelete();

            $table->timestamp('posted_at')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The list, most recent month first, and the "has this month already
            // been run" lookup.
            $table->index(['tenant_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
