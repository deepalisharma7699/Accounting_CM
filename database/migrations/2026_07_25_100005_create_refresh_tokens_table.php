<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // `jti` of the issued refresh JWT. Lookup key on refresh/logout.
            $table->uuid('jti')->unique();

            // SHA-256 of the raw token string. The token itself is never
            // stored, so a database leak cannot be replayed against the API.
            $table->char('token_hash', 64)->unique();

            // All tokens descending from one login share a family id. Reusing
            // an already-rotated token revokes the entire family.
            $table->uuid('family_id')->index();

            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 40)->nullable();
            $table->uuid('replaced_by_jti')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
