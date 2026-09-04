<?php

use App\Enums\SalaryBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people who work for the workshop — M22.
 *
 * ## Why this is not `users`, and not `parties`
 *
 * Not `users`, because most of them will never sign in. A workshop with nine
 * staff has two logins, and modelling the fitter as a user account with no
 * password would put seven rows in the sign-in directory that can never sign in
 * — and would mean that granting somebody authority to add a login also granted
 * them every wage in the building. See {@see \App\Enums\PermissionResource::Staff}.
 *
 * Not `parties`, because a party's position lives in Receivables or Payables and
 * an employee's does not. What is owed to staff sits in Salary Expense and Staff
 * Advance, which are different accounts, read by different screens, answering a
 * different question. The `roles` column on `parties` was written with a "staff"
 * role in mind; that turned out to be the wrong shape, because none of what
 * matters about an employee — the basis, the rate, the joining date, the
 * attendance — has anywhere to live on a party.
 *
 * ## What is deliberately not here
 *
 * **No advance balance.** What is out with an employee is derived: the staff
 * advances posted against them, less what payroll has recovered. A stored
 * balance is the same mistake as a stored party outstanding or a `qty_on_hand`
 * column — it agrees with the truth right up until one of the two is written
 * without the other. See {@see \App\Services\Staff\PayrollService::advanceOutstandingFor()}.
 *
 * **No salary history.** A raise changes `pay_rate`, and every payroll run
 * already snapshots the rate it used onto its own lines — so what somebody was
 * paid in March is answered by March's payslip rather than by reconstructing it
 * from a history table. The *decision* to raise it is on the audit trail.
 *
 * **No bank details, no PAN, no PF number.** None of them is used by anything
 * this module does, and a field nobody writes to is a field that is wrong when
 * somebody finally reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->string('name', 150);

            // Nullable, and `nullOnDelete` rather than `restrict`: a designation
            // that is archived and later removed must not take an employee's
            // record with it, and "no designation" is a legitimate state for
            // somebody added in a hurry.
            $table->foreignId('designation_id')->nullable()
                ->constrained('staff_designations')->nullOnDelete();

            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('notes', 500)->nullable();

            /*
            | How the pay is measured, and the rate it is measured in.
            |
            | Two columns rather than one, because the *number* means something
            | different in each case: 18000 on `monthly` is a salary and 550 on
            | `daily` is a day rate, and there is no single figure that could
            | stand for both. Payroll multiplies the second by a count of days
            | that the first divides by — see PayrollCalculator.
            */
            $table->string('salary_basis', 20)->default(SalaryBasis::Monthly->value);
            $table->decimal('pay_rate', 15, 2)->default(0);

            /*
            | In service between these two dates, inclusive.
            |
            | Both matter to the arithmetic rather than being biography: somebody
            | who joined on the 12th is paid for nineteen days of a 30-day month,
            | not for a month. `left_on` is nullable and means "still here".
            */
            $table->date('joined_on');
            $table->date('left_on')->nullable();

            // Archived rather than deleted once anything points at them, exactly
            // as a party is: their attendance and their payslips would otherwise
            // lose the name that explains them.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
            | One name, one employee.
            |
            | The same protection `parties` takes, and it earns it here too: two
            | rows called "Ramesh" means one of them is marked present every day
            | and the other is paid nothing, and both look plausible on their own
            | screen. A workshop with two Rameshes has to write "Ramesh (winder)",
            | which is what everybody already calls him anyway.
            */
            $table->unique(['tenant_id', 'name']);

            // The list, which is almost always active-only and name-ordered.
            $table->index(['tenant_id', 'is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
