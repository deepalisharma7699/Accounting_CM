<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Lifecycle status: active | inactive | suspended | pending.
            // Anything other than "active" is refused at login and by the
            // auth guard, so deactivating a user takes effect immediately.
            $table->string('status', 20)->default('active')->after('password')->index();

            // The user's custom role. Nullable: a user without a role simply
            // has no permissions rather than being an invalid record.
            $table->foreignId('custom_role_id')
                ->nullable()
                ->after('status')
                ->constrained('roles')
                ->nullOnDelete();

            // Per-account lockout counters (complements IP rate limiting).
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('custom_role_id');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');

            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custom_role_id');
            $table->dropColumn([
                'status',
                'failed_login_attempts',
                'locked_until',
                'last_login_at',
                'last_login_ip',
                'deleted_at',
            ]);
        });
    }
};
