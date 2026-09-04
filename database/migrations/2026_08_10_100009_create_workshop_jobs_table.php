<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The motor on the bench — M19, and the brief's §16 to §18.
 *
 * ## Why this is a table of its own and not a draft sale
 *
 * Because a job exists before any money does. A pump motor is received on the
 * 3rd, opened up on the 5th, quoted on the 6th, approved on the 9th, rewound
 * over the following week and billed when the customer comes for it. Modelling
 * that as a draft invoice would put a document with a customer and no items in
 * the books' draft queue for a fortnight, where every worklist and every "finish
 * your unposted work" prompt would nag about it — and where somebody would
 * eventually post it to make the nagging stop.
 *
 * It would also be false. A draft is a document somebody has started writing; a
 * job is a physical object with a fault, and its statuses are about the object.
 * `in_progress` is not a state an invoice can be in.
 *
 * ## Why the table is `workshop_jobs`
 *
 * `jobs` is Laravel's queue table, and `job_runs` is M14's record of background
 * work. Three different things would otherwise be competing for one word. The
 * workshop one takes the qualifier because it is the one a reader is least
 * likely to guess wrong — in this trade a "job" is the motor, and only a
 * programmer would read it as a queued closure.
 *
 * ## What is deliberately not here
 *
 * **No total, and no amount of any kind.** What a job is worth is the bill
 * raised from it, and that lives in `transactions` where every other figure in
 * this application lives. A stored total would be a second copy of the invoice
 * that disagrees with it the first time a line is changed — the same mistake as
 * a stored party balance or a `qty_on_hand` column, which is why neither exists
 * either.
 *
 * **No parts.** They are rows in `workshop_job_parts`, because there are many of
 * them and because each one eventually points at the bill line that consumed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_jobs', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // `JOB/26-27/41`. Taken from the same locked counter every invoice
            // number comes from — see DocumentNumberService — because two motors
            // on two benches carrying one ticket number is the same unrecoverable
            // mess as two invoices carrying one number, arrived at from the other
            // direction. Unlike an invoice this is assigned at *creation*: a job
            // has to be labelled before anybody can put a sticker on the casing,
            // and there is no draft state for it to be discarded from.
            $table->string('job_no', 40);

            // Required. A motor belongs to somebody — that is the whole reason it
            // is on the bench rather than in the scrap bin — and a job attributed
            // to nobody could never be billed or returned.
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();

            /*
            | The motor itself.
            |
            | `item_id` is nullable and optional, and the free text below is not a
            | fallback for it — the two answer different questions. The catalogue
            | says what the workshop *deals in*; these columns say what was
            | actually wheeled through the door, which is very often a competitor's
            | forty-year-old unit that will never be in anybody's catalogue. A
            | schema that insisted on a catalogue row would have the counter
            | inventing one per motor.
            |
            | Copied rather than joined for the same reason a bill line copies its
            | description: the plate on the casing said 7.5 HP on the day it
            | arrived, and it must still say so on the job card next year.
            */
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('hp', 20)->nullable();
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            // The one field that identifies *this* motor rather than its kind,
            // and the one a customer quotes down the phone.
            $table->string('serial_no', 60)->nullable();
            $table->string('phase', 20)->nullable();

            // What the customer said was wrong, in their words. Required, because
            // a job with no complaint is a motor nobody can say why they have.
            $table->text('complaint');

            $table->date('received_date');
            $table->date('promised_date')->nullable();

            $table->string('status', 20)->default('received');

            /*
            | The quotation — §18.
            |
            | JSON on the job rather than a transaction, and that is decision D3:
            | an estimate that posted journal entries would be claiming revenue
            | nobody has agreed to, and reversing it when the customer says no
            | would leave a cancelled invoice on a job that never happened.
            |
            | Same shape as a bill's `items`, so approving one and billing it is a
            | copy rather than a translation.
            */
            $table->json('estimate_lines')->nullable();
            $table->timestamp('estimate_approved_at')->nullable();

            // When the motor actually left. Distinct from the status: the status
            // is what a worklist filters on, this is the fact somebody needs when
            // a customer rings to ask when they collected it.
            $table->timestamp('delivered_at')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Per workshop, not globally: two workshops both running JOB/26-27/1
            // is correct, and a global unique index would make one of them wait
            // for the other's numbering.
            $table->unique(['tenant_id', 'job_no']);

            // The worklist: "what is open, newest first". The status leads because
            // every screen in this module filters on it first.
            $table->index(['tenant_id', 'status', 'received_date']);

            // "Everything we have ever done for this customer" — the job history
            // on a party's drawer.
            $table->index(['tenant_id', 'party_id']);
        });

        // Restating the service's rules where nothing can bypass them, exactly as
        // transaction_lines and transaction_allocations do.
        //
        // MySQL only (8.0.16+, and this application is MySQL-only by design).
        // Skipped on any other driver rather than failing, so the schema still
        // builds.
        if (DB::getDriverName() === 'mysql') {
            // A motor promised before it arrived is a typo, and it would put the
            // job at the top of an overdue list on the day it was written.
            DB::statement(
                'ALTER TABLE workshop_jobs ADD CONSTRAINT workshop_jobs_promised_after_received
                 CHECK (promised_date IS NULL OR promised_date >= received_date)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_jobs');
    }
};
