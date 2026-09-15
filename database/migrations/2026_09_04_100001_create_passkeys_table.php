<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per device a person has enrolled.
 *
 * Nothing here is a secret. The public key is public by construction, which is
 * the property that makes this different from `users.password`: that column is
 * a hash of something replayable, and this one is a verifier that is useless to
 * whoever steals it. A dump of this table does not sign anybody in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
            | The credential id the authenticator generated, base64url encoded
            | so it is a string everywhere — in the column, in the JSON the
            | browser sends, and in the index this is looked up by.
            |
            | Unique across the whole table rather than per user: the same
            | device key may not be claimed by two accounts, or signing in with
            | it would be ambiguous. The spec caps a raw id at 1023 bytes; the
            | length here is what stays inside InnoDB's 3072-byte index limit
            | at utf8mb4 while covering every id in practice.
            */
            $table->string('credential_id', 510)->unique();

            /*
            | The verifier itself — the whole CredentialRecord, serialized by
            | the WebAuthn library that will later have to read it back.
            |
            | Stored whole rather than exploded into columns on purpose: the
            | shape belongs to the library and the spec, and a column per field
            | is a migration every time either moves. The few things this
            | application queries or shows are denormalised below.
            */
            $table->text('credential');

            /*
            | What the person calls this device. It is the only thing that makes
            | the list actionable — "revoke the old phone" is impossible against
            | four rows that all say "passkey".
            */
            $table->string('label', 80);

            // Which model of authenticator, as reported. Recorded because it is
            // the one clue about *what* an unrecognised enrolment was made on.
            $table->uuid('aaguid')->nullable();

            /*
            | The authenticator's own counter, and the reason it is here: a
            | counter that goes backwards means two things are answering for one
            | credential, which is a cloned authenticator. Kept in step on every
            | assertion so the comparison has something to be made against.
            |
            | Many passkeys — the synced ones — legitimately report zero and
            | never move, so a zero counter proves nothing either way.
            */
            $table->unsignedBigInteger('sign_count')->default(0);

            // Whether the credential is synced to the person's account (an
            // iCloud or Google passkey) rather than living only on this device.
            // It decides what losing the device actually costs them.
            $table->boolean('backed_up')->default(false);

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
