<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Roles become tenant-based.
|
| A role with a NULL tenant_id is a platform role — the seeded system roles and
| anything the platform administrator created for everybody. A role with a
| tenant_id belongs to that workshop alone: only its own people can see it,
| assign it, or change it.
|
| Uniqueness is per scope, not global. MySQL treats NULLs as distinct in a
| unique index, which would let two platform roles share a name, so the scope
| is folded into a generated column and the unique keys are built on that
| instead — the same trick, and for the same reason, as the other
| nullable-scope uniques in this schema.
|
| Existing rows all become platform roles, which is what they were. Nothing here
| touches users, tenants or grants.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropUnique(['slug']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained('tenants')->restrictOnDelete();

            $table->unsignedBigInteger('scope_key')->storedAs('coalesce(`tenant_id`, 0)');

            $table->unique(['scope_key', 'name']);
            $table->unique(['scope_key', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['scope_key', 'name']);
            $table->dropUnique(['scope_key', 'slug']);
            $table->dropColumn('scope_key');
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique('name');
            $table->unique('slug');
        });
    }
};
