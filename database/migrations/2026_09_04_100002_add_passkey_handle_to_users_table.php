<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The opaque id a person's devices know them by.
 *
 * A discoverable passkey stores a user handle on the device and hands it back
 * at sign-in, before this server has been told who is signing in. It therefore
 * has to be something we can look an account up by — and deliberately *not*
 * the primary key or the email address, because it is written to hardware this
 * application does not control and read by anything the person taps "sign in
 * with a passkey" on. A random value leaks nothing if it is read.
 *
 * One handle per person rather than one per device, so a second passkey
 * enrolled on a second phone identifies the same account, and so the browser
 * shows one entry for this workshop instead of one per device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('passkey_handle')->nullable()->unique()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['passkey_handle']);
            $table->dropColumn('passkey_handle');
        });
    }
};
