<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follows — a user (`follower_id`) following a Company or another User
 * (`followable_type`/`followable_id`). Polymorphic on the followed side for
 * the same reason `favorites` is: one table for both followable kinds,
 * mirroring the `Capacity::owner()` MorphTo precedent.
 *
 * Unique on the (follower, followable_type, followable_id) triple so
 * following twice is idempotent at the database layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->string('followable_type');
            $table->unsignedBigInteger('followable_id');
            $table->timestampsTz();

            $table->unique(['follower_id', 'followable_type', 'followable_id'], 'follows_follower_followable_unique');
            $table->index(['followable_type', 'followable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
