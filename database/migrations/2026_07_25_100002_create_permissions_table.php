<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();

            // A permission is an (action, resource) pair, e.g. READ / USERS.
            // The wildcard "*" is a legal value for either side and is what
            // gives the seeded ADMIN role its full access grant.
            $table->string('action', 64);
            $table->string('resource', 64);
            $table->string('description', 255)->nullable();

            $table->timestamps();

            $table->unique(['action', 'resource']);
            $table->index('resource');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
