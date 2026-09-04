<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person's pay for one month — the payslip — M22.
 *
 * The ledger holds the money and this holds the breakdown, and neither is a
 * second copy of the other. A payroll run posts **one** journal: Salary Expense
 * debited with the whole gross, Staff Advance credited with everything
 * recovered, and cash, bank or UPI credited with what went out. Who got what is
 * not recoverable from those three lines at all, which is exactly why these rows
 * exist — and why they are not an accounting duplicate of anything.
 *
 * ## Everything here is a snapshot, on purpose
 *
 * The name, the designation, the basis and the rate are copied rather than
 * joined. A workshop raises a wage in November and the October payslip must
 * still say what October said, for the same reason a bill line copies its
 * description: the document records what was true at the time, and a join would
 * rewrite history every time somebody edited a master.
 *
 * The attendance counts are snapshotted for a sharper version of the same
 * reason. Attendance stays editable after the fact — somebody remembers a
 * Saturday a fortnight later — and a payslip that recomputed itself would
 * quietly stop agreeing with the money that was actually handed over against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // cascadeOnDelete: a line has no meaning without its run, and the two
            // are written as one act.
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();

            // restrict, so an employee cannot be deleted out from under a
            // payslip. Somebody who has left is archived instead.
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();

            /* --- the snapshot, as it stood on the day this was posted -------- */

            $table->string('employee_name', 150);
            $table->string('designation', 80)->nullable();
            $table->string('salary_basis', 20);
            $table->decimal('pay_rate', 15, 2);

            /*
            | The arithmetic, in half-days.
            |
            | Halves rather than a decimal `days` column because a half day is the
            | only fraction this trade uses, and halves in integers are exact —
            | a half plus a half plus a half is not 1.5 in binary floating point,
            | and a month of them drifts by paise against a figure an employee
            | counts on their fingers. The same rule Money applies to rupees.
            |
            | `paid_half_days` is what was earned. `period_half_days` is what the
            | month was worth in full — the denominator a monthly salary is
            | pro-rated over, and the figure that makes "19 of 30 days" legible on
            | a payslip. For a daily wage the second is context rather than
            | arithmetic: the rate is multiplied, not divided.
            */
            $table->smallInteger('paid_half_days');
            $table->smallInteger('period_half_days');

            /*
            | How many of each kind of day were marked — present, absent, half,
            | leave, holiday, week off — plus how many were left unmarked.
            |
            | JSON rather than six columns, because it is a vocabulary that may
            | gain a member and because this is a record of what was counted
            | rather than something anything queries across rows. The one figure
            | that *is* queried, what was paid, is a column above.
            */
            $table->json('attendance');

            /* --- the money --------------------------------------------------- */

            $table->decimal('gross', 15, 2);

            /*
            | Taken out of this month's pay and set against what the employee
            | already owes. Never more than the outstanding advance and never more
            | than the gross — a payslip cannot end with the employee owing the
            | workshop money, because that is not something a payroll run can do.
            */
            $table->decimal('advance_recovered', 15, 2)->default(0);

            // gross less advance_recovered. Stored rather than derived on read
            // for the same reason `transactions.total` is: it is what was handed
            // over, and every list shows it.
            $table->decimal('net', 15, 2);

            $table->string('notes', 190)->nullable();

            $table->timestamps();

            // One line per person per run. A second would pay somebody twice out
            // of a voucher whose total says otherwise.
            $table->unique(['payroll_run_id', 'employee_id']);

            // "What has this person been paid, and what has been recovered from
            // them" — the drawer, and the advance-outstanding derivation.
            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
