<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per user. `channels` gates delivery MECHANISM (push/email);
 * `types` gates WHICH kind of event is recorded at all (a `false` type
 * suppresses even the database row — see `NotificationPreference::allows()`).
 * Both default to all-true so a user who never visits preferences keeps
 * getting everything, matching current behaviour before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('channels')->default('{"push":true,"email":true}');
            $table->jsonb('types')->default('{"quote_received":true,"order_status_changed":true,"message_received":true,"dispute_reply":true}');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
