<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stored evidence: a photographed invoice, a recorded instruction, a PDF bill —
 * M14, and the input M15's capture agent reads.
 *
 * ## What this table holds and what it does not
 *
 * A pointer, never the bytes. The object lives on the disk named in `disk` at
 * the key named in `path`, and this row is the record of it: what it is, how big
 * it was, what its digest was, who uploaded it and whether it was ever read back
 * successfully. Putting the bytes in the database would make every backup of the
 * books carry a workshop's photographs, and make every query that touches this
 * table expensive for the one reason nobody would predict.
 *
 * ## Why there is no `transaction_id`
 *
 * Because nothing attaches a file to a transaction yet. M15 is what will, and it
 * will add the column and its foreign key together — exactly as M4 deferred
 * `transactions.party_id` to M5 rather than leaving it nullable and
 * unconstrained for a module. A reserved empty column is an invitation, and the
 * thing it invites is a row that points at nothing while the schema claims it
 * points at something.
 *
 * ## Why the digest is indexed but not unique
 *
 * Uploading the same photograph twice is worth *saying* and not worth refusing.
 * A workshop that photographs one invoice for the purchase and again for the
 * payment has done something reasonable; a second copy costs a few kilobytes and
 * the alternative — quietly handing back the first row — creates a file that two
 * things point at and that either of them may delete. The same treatment as a
 * shared GSTIN in M5 and a duplicate specification in M7: reported, not refused.
 *
 * ## Why `path` carries the tenant
 *
 * The keys are `tenants/{id}/{kind}/{year}/{month}/{ulid}.{ext}`. The tenant is
 * in the path although every read is already filtered by the tenant scope,
 * because storage is the one place in this application where a bug would not be
 * caught by that scope: an object key is a string, and a string assembled wrongly
 * reaches whatever it names. With the tenant in the prefix a mis-scoped read is
 * visible in a bucket listing, a bucket policy can enforce the boundary
 * independently of the application, and a workshop's data can be exported or
 * deleted by prefix when they leave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            // NOT NULL — tenant-owned, enforced by TenantIsolationInvariantTest.
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();

            // invoice_image | audio | document — see AttachmentKind. Declared by
            // the uploader and then checked against the bytes, because the kind
            // decides how M15 will try to read the file.
            $table->string('kind', 30);

            // Which disk, recorded per row rather than read from config at use
            // time. An operator who moves from local storage to S3 must not find
            // that yesterday's uploads have become unreadable because a config
            // key changed underneath them.
            $table->string('disk', 30);

            // The object key. Never the filename the client sent — see the note
            // above on the path scheme.
            $table->string('path', 255);

            // What the workshop called it. Display and downloads only: it is
            // user-supplied text and is never used to build a path.
            $table->string('original_name', 255);

            // The *verified* media type, sniffed from the bytes rather than
            // taken from the upload's Content-Type header, which is whatever the
            // client chose to claim.
            $table->string('mime_type', 100);

            $table->unsignedBigInteger('size_bytes');

            // SHA-256 of the bytes as received. Two jobs: the duplicate notice
            // above, and the integrity check that promotes a row from `pending`
            // to `ready` once the object has been read back and found to match.
            $table->char('checksum', 64);

            // pending | ready | failed — see AttachmentStatus. A write to object
            // storage can return cleanly and still leave nothing readable, so
            // nothing is `ready` until it has been read back.
            $table->string('status', 20)->default('pending');

            // The verification run, so a screen can poll one URL for progress
            // and show why a file did not store. nullOnDelete because job runs
            // are pruned and the attachment must outlive them.
            $table->foreignId('job_run_id')->nullable()->constrained('job_runs')->nullOnDelete();

            // Whatever the kind's processing learned: image dimensions, audio
            // duration. Small, and never a second copy of the file's content.
            $table->json('meta')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Two objects at one key would mean one silently overwriting the
            // other's bytes — a photograph replaced by a different photograph,
            // with nothing in the schema to say so. The ULID in the key makes it
            // effectively impossible; the index makes it actually impossible.
            $table->unique(['disk', 'path']);

            // The library: most recent first.
            $table->index(['tenant_id', 'created_at']);

            // The duplicate notice.
            $table->index(['tenant_id', 'checksum']);

            // "Everything still being checked", and the per-kind list M15 will
            // ask for.
            $table->index(['tenant_id', 'kind', 'status']);
        });

        // MySQL only (8.0.16+, and this application is MySQL-only by design; see
        // tenancy-module.md). Skipped on any other driver rather than failing, so
        // the schema still builds.
        if (DB::getDriverName() === 'mysql') {
            // A stored file of nothing is not a stored file. It would sit in the
            // library offering an empty download.
            DB::statement(
                'ALTER TABLE attachments ADD CONSTRAINT attachments_size_positive
                 CHECK (size_bytes > 0)'
            );

            // A row that cannot name its object cannot fetch it, and an
            // unreachable object is storage nobody will ever reclaim.
            DB::statement(
                "ALTER TABLE attachments ADD CONSTRAINT attachments_path_present
                 CHECK (path <> '')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
