<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which employee an advance was paid to — M22.
 *
 * ## Why not `party_id`
 *
 * Because `party_id` is a claim about a relationship, not a label. A transaction
 * carrying one is saying that this counterparty's position moved in Receivables
 * or in Payables — that is what {@see \App\Enums\PartyRole::controlAccount()}
 * means, and it is why the posting engine refuses a payment to a customer-only
 * party. An advance moves neither account: it debits Staff Advance, which is the
 * workshop's own asset. Pointing it at a party would either invent a supplier
 * relationship that does not exist or put a name on a statement that could never
 * account for it.
 *
 * ## Write-once provenance, exactly like `workshop_job_id`
 *
 * Stamped by {@see \App\Services\Staff\AdvanceService} inside the same database
 * transaction as the posting, so the money and the person it went to commit
 * together or not at all — and then never changed. See
 * `Transaction::STAMPABLE_ONCE_POSTED`, which this column joins. A column that
 * could be re-pointed would let one employee's advance be recovered from
 * another's salary, which is the one mistake in this module that ends with
 * somebody being underpaid and nothing on any screen explaining why.
 *
 * ## What does not carry one
 *
 * A **payroll** transaction. It pays everybody at once, so no single employee is
 * its counterparty; who got what is `payroll_lines`, and `payroll_runs` points at
 * the transaction from the other side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // restrictOnDelete, matching every other reference to an employee:
            // somebody who has left is archived, never deleted, or the advance
            // loses the name that explains it.
            $table->foreignId('employee_id')->nullable()->after('party_id')
                ->constrained('employees')->restrictOnDelete();

            // "What is out with this person" — the advance outstanding, read on
            // the staff list, the drawer, and every payroll preview.
            $table->index(['tenant_id', 'employee_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'employee_id', 'type']);
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
