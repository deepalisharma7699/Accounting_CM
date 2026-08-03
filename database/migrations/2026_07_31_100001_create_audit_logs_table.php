<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what, when — M13.
 *
 * ## What this table is for, and what it is not for
 *
 * It is *not* the ledger's audit trail. The ledger already is one: a posted
 * transaction cannot be edited or deleted, `journal_entries` and
 * `stock_movements` refuse an UPDATE on the model, and `created_by` and
 * `posted_at` sit on every transaction. Recording "journal entry 4,102 was
 * created" here would be a second copy of a fact the first copy already proves,
 * and two copies of one truth is the arrangement this codebase refuses
 * everywhere else.
 *
 * What has no trail is the *master data underneath the figures* — the chart of
 * accounts, the parties, the catalogue, the workshop's own settings, its users.
 * Those are mutable by design, they change silently, and every one of them
 * changes what the books mean without changing a single posted number. Archiving
 * a supplier removes them from every picker. Editing a party's GSTIN changes
 * which side of a tax return their invoices land on. Moving the financial year
 * start re-cuts every period report ever run. None of that is visible in
 * `journal_entries`, and this table is the answer.
 *
 * ## Why the actor's name is copied
 *
 * `actor_id` is nullable and `nullOnDelete`, because a user can be deleted and
 * the trail must survive them — a history that empties itself when somebody
 * leaves is not a history. So the name is copied at the moment of the act.
 * Everywhere else in this schema a denormalised copy is refused as a stored
 * aggregate that will drift; this one is different in kind. It is not a copy of
 * a *current* fact that could disagree with its source, it is a copy of a *past*
 * one: the name that person went by when they did it. If they marry and change
 * it, the old rows are still correct. The same reasoning applies to `label`,
 * which records what the record was called at the time — an account renamed from
 * "Petty Cash" to "Cash in Hand" leaves a trail that reads as it read then.
 *
 * ## Why there is no updated_at
 *
 * Rows are written once and never touched, guarded on the model exactly as a
 * journal entry is. An `updated_at` column on an immutable row is a permanent
 * lie, and an audit log that can be edited is not evidence of anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            //
            // Note that this is the tenant the change was made *to*, not the
            // tenant the actor belongs to. A platform administrator editing a
            // workshop's settings writes a row into that workshop's history,
            // which is where somebody looking for it will go.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // Nullable in both senses: the row survives the user's deletion, and
            // some acts have no user behind them at all — a console command, a
            // queued job, a seeder.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // The name at the time. See the note above on why this copy is not
            // the stored aggregate this schema forbids elsewhere.
            $table->string('actor_name', 120)->nullable();

            // created | updated | archived | restored | deleted — see AuditAction.
            $table->string('action', 20);

            // A stable key from AuditResource, never a class name: a class name
            // is a promise never to move the class, and it is broken silently.
            $table->string('resource', 30);
            $table->unsignedBigInteger('resource_id');

            // What the record was called when it was touched.
            $table->string('label', 200);

            // { field: { from, to } }, and only for the fields that actually
            // moved. Built from an allow-list declared on each model rather than
            // from whatever happened to be dirty — see the Auditable trait — so a
            // password hash or a lockout counter cannot end up in a table an
            // owner is allowed to read.
            //
            // Named `changed_fields` and not the obvious `changes`, which was the
            // first attempt and is a trap: `Model::$changes` is Eloquent's own
            // protected property holding what the last save modified. A column of
            // that name reads correctly from outside the class and silently
            // returns the framework's empty internal array from *inside* it, so
            // any accessor on the model itself gets nothing and reports no
            // changes at all. It fails without an error, which is the worst way
            // for an audit trail to fail. The JSON the API sends is still called
            // `changes`, because that is the right word for a client.
            $table->json('changed_fields')->nullable();

            // Ambient detail: the caller's IP, and how the change arrived. Small
            // and deliberately unstructured — anything worth filtering on gets a
            // column.
            $table->json('context')->nullable();

            // created_at only. The row is written once; see the note above.
            $table->timestamp('created_at')->nullable();

            // The history list, newest first — the default read.
            $table->index(['tenant_id', 'created_at']);

            // One record's own history: "everything that ever happened to this
            // party". Also what a per-record panel on a screen would ask for.
            $table->index(['tenant_id', 'resource', 'resource_id', 'id']);

            // What one person did — the question asked after somebody leaves, or
            // after something is found to be wrong.
            $table->index(['tenant_id', 'actor_id', 'id']);
        });

        // Restating in the database what the recorder already refuses, exactly as
        // journal_entries and stock_movements do. The premise of this table is
        // that it can be trusted, and a raw query or a mistaken migration must
        // not be able to write a row that points at nothing.
        //
        // MySQL only (8.0.16+, and this application is MySQL-only by design; see
        // tenancy-module.md). Skipped on any other driver rather than failing, so
        // the schema still builds.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_resource_id_positive
                 CHECK (resource_id > 0)'
            );

            // A trail entry that cannot name what it refers to is noise, and
            // noise in an audit log is worse than a gap: a gap is visible.
            DB::statement(
                "ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_label_present
                 CHECK (label <> '')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
