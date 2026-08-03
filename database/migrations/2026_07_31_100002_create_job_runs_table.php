<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the queue is doing, in terms a workshop can see — M14.
 *
 * ## Why this exists beside Laravel's own `jobs` table
 *
 * Because `jobs` is the queue's own bookkeeping and answers none of the
 * questions a user has. A row there is a serialised closure that vanishes the
 * moment the worker picks it up, so "is my invoice being read yet" has no answer
 * at all between the pick-up and the finish, and "did it work" has no answer
 * afterwards — success leaves nothing behind. `failed_jobs` records the
 * failures and nothing else, which means the only visible state is the bad one.
 *
 * This table is the other half: one row per piece of work the *application*
 * cares about, created at dispatch and outliving the job in both directions. It
 * is what a progress bar polls and what a screen reads to say "your upload
 * failed at 3:14 and here is why".
 *
 * ## Why progress is a stored number and not derived
 *
 * Every other number in this schema is a sum over rows, and the reason is always
 * the same — a stored aggregate drifts from its source. There is no source here.
 * A job's progress is a fact about a process running in another machine's
 * memory, and the only thing that knows it is the job itself. Storing it is not
 * a shortcut around a derivation; it is the derivation.
 *
 * That also makes it the one figure in this application that is allowed to be
 * stale, and it is deliberately not trusted for anything: `status` is what
 * decides whether work is finished, and progress is a courtesy. A worker killed
 * mid-run leaves a row reading "47%" for ever, which is why the screens show
 * elapsed time beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            //
            // The consequence for the worker is the important one: a queued job
            // runs with no request behind it and therefore no tenant context, so
            // it re-establishes the tenant from what it captured at dispatch and
            // finds this row *through* the scope. A job that somehow ran as the
            // wrong tenant would not find its own run row, and would fail loudly
            // rather than write into the wrong workshop.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // The public handle. A UUID rather than the id, because it is what a
            // client polls with: an incrementing integer in a URL invites a
            // caller to try the one next to it, and although the tenant scope
            // would refuse them, a system whose safety rests on a check being
            // present is weaker than one where guessing is pointless.
            $table->uuid('uuid')->unique();

            // A stable key from the job class — `attachment.process`, not
            // `App\Jobs\ProcessAttachment`. Same reasoning as audit_logs.resource:
            // a class name in a column is a promise never to move the class, and
            // it is broken silently.
            $table->string('type', 60);

            // queued | running | succeeded | failed — see JobStatus.
            $table->string('status', 20)->default('queued');

            // 0–100. A courtesy, not an authority: `status` decides whether the
            // work is finished. See the note above.
            $table->unsignedTinyInteger('progress')->default(0);

            // Where the percentage came from, when the job knows. "312 of 480
            // rows" is a far better thing to show somebody than "65%", and a job
            // that cannot count its work leaves both null and reports a message
            // instead.
            $table->unsignedInteger('processed')->nullable();
            $table->unsignedInteger('total')->nullable();

            // What the job would say if asked. Written for a human.
            $table->string('message', 255)->nullable();

            // What it was asked to do — the ids it was handed, never the data
            // itself. Enough to understand the row six weeks later without
            // making this a second copy of the work.
            $table->json('payload')->nullable();

            // What it produced. Small: counts and ids, not documents.
            $table->json('result')->nullable();

            // { message, exception, at }. Deliberately not the stack trace —
            // that is `failed_jobs`' job, and this column is read by an owner.
            $table->json('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Who set it going. Nullable in both senses: the row survives their
            // deletion, and scheduled work has nobody behind it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The list: most recent first.
            $table->index(['tenant_id', 'created_at']);

            // "Is anything still running" — the query behind a badge, and the
            // one a polling client makes most often.
            $table->index(['tenant_id', 'status', 'id']);
        });

        // MySQL only (8.0.16+, and this application is MySQL-only by design; see
        // tenancy-module.md). Skipped on any other driver rather than failing, so
        // the schema still builds.
        if (DB::getDriverName() === 'mysql') {
            // A percentage outside 0–100 renders as a progress bar wider than
            // its container, which is a small bug that makes a screen look
            // broken. unsignedTinyInteger already caps at 255.
            DB::statement(
                'ALTER TABLE job_runs ADD CONSTRAINT job_runs_progress_in_range
                 CHECK (progress BETWEEN 0 AND 100)'
            );

            // Finishing before starting is not a clock skew to be tolerated, it
            // is a bug in whatever wrote the row.
            DB::statement(
                'ALTER TABLE job_runs ADD CONSTRAINT job_runs_finished_after_started
                 CHECK (finished_at IS NULL OR started_at IS NULL OR finished_at >= started_at)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
