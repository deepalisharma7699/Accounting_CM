<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            /*
            | Which module cards the workshop wants at the top of its home
            | screen — a list of keys from config/modules.php, in the order they
            | are shown.
            |
            | A column on the workshop rather than on the user, which is the one
            | decision in here worth knowing about: the owner sets one list and
            | everybody in the workshop gets it. A card nobody's grants allow is
            | already hidden by the permission gates, so a clerk never sees a
            | favourite they cannot open — the list narrows per reader without
            | being stored per reader.
            |
            | Nullable, and null is "never set" rather than "none chosen". The
            | two behave identically today; the distinction costs nothing and is
            | what would let the first-run hint below ever differ from a
            | deliberately emptied list.
            |
            | json rather than a delimited string: the application reads it as a
            | list, and a comma-separated column is a list nobody validates.
            */
            $table->json('favourite_modules')->nullable()->after('round_off_invoices');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('favourite_modules');
        });
    }
};
