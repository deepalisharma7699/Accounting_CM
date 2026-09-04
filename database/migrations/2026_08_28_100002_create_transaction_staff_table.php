<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who did the work an invoice was raised for — M22.
 *
 * "Ramesh fitted it, Sunil wound it." One row per trade per invoice, which is
 * what makes the two questions a workshop actually asks answerable: which of my
 * people did this motor, and how much has this person got through this month.
 *
 * ## Why a table and not two columns on `transactions`
 *
 * The same reason `track_on_sales` is a flag and not a schema decision — the
 * trades are data. A workshop that starts attributing a third one adds a row to
 * the Designation Master and this table carries it that afternoon.
 *
 * There is a second reason, and it is the stronger one: `transactions` is a
 * ledger table, and a row in it is immutable once posted. A mis-picked fitter is
 * not a financial error and must not be corrected the way one is — see below.
 *
 * ## Why not `transactions.employee_id`
 *
 * That column is spoken for, and it means something else. It records who a staff
 * *advance* was handed to — a claim about a counterparty, stamped once and never
 * changed, because re-pointing it would let one employee's advance be recovered
 * from another's salary. Attribution is a label on work, there are two of them
 * per invoice, and one column could hold neither fact honestly.
 *
 * ## Why this is correctable and a posted transaction is not
 *
 * Because nothing here is a figure. A posted invoice is immutable so that a
 * report run yesterday still produces yesterday's numbers, and not one number
 * moves when the wrong fitter's name is swapped for the right one — no ledger
 * entry, no stock movement, no total.
 *
 * Correcting it through the ordinary route would mean reversing the invoice and
 * reissuing it, and on a *sale* that is not merely heavy — it is refused.
 * `assertRevisionKeepsTheCostItSoldAt` compares the unit cost the reversal
 * returned against the cost the replacement issues at, and a weighted average
 * that has moved in the meantime fails it with `REVISION_WOULD_RESTATE_COST`,
 * which has no acknowledgement path. So a write-once attribution would leave the
 * workshop with a name it knows is wrong, permanently, and its productivity
 * figures crediting the wrong person for as long as they keep the records.
 *
 * Editing is therefore allowed and audited: every change lands in `audit_logs`
 * with both names on it, so the correction is on the trail even though the
 * invoice it hangs off never moved.
 *
 * ## What is deliberately not here
 *
 * **No line grain.** The attribution covers the document. A bill for one motor
 * is the ordinary case, and per-line attribution would put two more pickers on
 * every row of the lines table — including the bearings and the varnish, where
 * the question means nothing. A workshop that genuinely does two motors for one
 * customer on one day raises two invoices, which it already has every other
 * reason to do.
 *
 * **No hours, no piece rate, no share of the bill.** This says who did the work,
 * not what the work was worth to them. The moment a percentage lands here it is
 * an input to somebody's pay, and pay is `payroll_lines` — computed from a rate
 * and an attendance sheet, in one place, by one calculator.
 *
 * **No purchases.** A purchase is goods arriving from a supplier; nobody in the
 * building fitted it. {@see \App\Services\Staff\WorkAttributionService} refuses
 * a non-sale outright rather than accepting and ignoring it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_staff', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            /*
            | `cascadeOnDelete`, and it is the only cascade on this row.
            |
            | A posted invoice is never deleted — a draft is, and discarding one
            | must take its attribution with it rather than leaving a row
            | pointing at a transaction that no longer exists. Nothing is lost:
            | the draft was the only thing that ever knew about it.
            */
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();

            /*
            | The person, and the trade they did it in.
            |
            | Both `restrictOnDelete`, which is what makes the record readable
            | years later: an employee who has left is archived rather than
            | deleted, exactly as a party is, and a designation nobody holds can
            | only be removed once nothing points at it — see
            | DesignationService::delete(), which counts these rows as well as
            | employees before it allows one to go.
            |
            | `designation_id` is the slot that was filled, not a copy of the
            | employee's own trade. A helper who wound a motor on a busy Friday
            | is recorded against Winder, because what is being recorded is the
            | work rather than the job title.
            */
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('designation_id')->constrained('staff_designations')->restrictOnDelete();

            $table->timestamps();

            /*
            | One person per trade per invoice.
            |
            | Two fitters on one bill is a question this schema refuses to answer
            | ambiguously: either the work was shared — in which case whose
            | throughput is it? — or the second row is a mis-click that would
            | quietly double the job count of whoever it named. The form offers
            | one picker per trade, and the index is what holds that true when
            | the request comes from anywhere else.
            */
            $table->unique(['transaction_id', 'designation_id']);

            /*
            | "How much has Ramesh got through this month."
            |
            | The report reads this table by employee and then joins the dates and
            | totals off `transactions`, so the employee leads the index. Ordered
            | before `designation_id` deliberately — the question is almost always
            | about a person, and only sometimes narrowed to a trade.
            */
            $table->index(['tenant_id', 'employee_id', 'designation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_staff');
    }
};
