<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this session was started by a passkey.
 *
 * A trusted session lives far longer than an ordinary one — that is the whole
 * of "signing in stops being a daily event". What earns it is *how* the session
 * started: a passkey is bound to one device and re-verified by fingerprint,
 * face or PIN at every use, so a long session on it stays as strong as the
 * moment it began. A typed password is a secret that can be shoulder-surfed,
 * reused from another site or written inside a cupboard door, and lengthening
 * its session lengthens exactly that exposure.
 *
 * The flag rides the whole rotation chain rather than being re-decided per
 * refresh: rotation issues a successor, and a successor that quietly reverted
 * to the short lifetime would sign the person out a week later with nothing to
 * show why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->boolean('trusted')->default(false)->after('family_id');
        });
    }

    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropColumn('trusted');
        });
    }
};
