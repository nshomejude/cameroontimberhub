<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Expo push token a mobile install has registered. A token is
 * unique platform-wide (not per-user): the same physical device re-registering
 * under a different account (logout/login as someone else) simply re-points
 * the existing row via `updateOrCreate(['expo_push_token' => ...], ...)`
 * rather than creating a duplicate that would double-deliver pushes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('expo_push_token')->unique();
            $table->string('platform', 20); // android|ios
            $table->string('device_name')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
