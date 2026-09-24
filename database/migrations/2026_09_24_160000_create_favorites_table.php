<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Favorites — a user bookmarking a Product or a Company for later. Polymorphic
 * on the favorited side (`favoritable_type`/`favoritable_id`) so both models
 * share one table rather than two near-identical ones, mirroring the
 * `favoritable()`/`owner()` MorphTo precedent already used by `Capacity`.
 *
 * Unique on the (user, favoritable_type, favoritable_id) triple so favoriting
 * twice is idempotent at the database layer, not just in application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('favoritable_type');
            $table->unsignedBigInteger('favoritable_id');
            $table->timestampsTz();

            $table->unique(['user_id', 'favoritable_type', 'favoritable_id'], 'favorites_user_favoritable_unique');
            $table->index(['favoritable_type', 'favoritable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
