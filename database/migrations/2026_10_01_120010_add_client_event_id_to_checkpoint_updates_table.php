<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline-replay idempotency for checkpoint capture from the mobile app:
 * the client mints a `client_event_id` once per captured checkpoint and
 * resends it on every retry. Unique per trackable, so a replayed POST maps
 * back to the row it already created instead of duplicating it. Nullable —
 * web/PWA and legacy rows don't carry one (Postgres treats NULLs as
 * distinct, so the unique index never collides on them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkpoint_updates', function (Blueprint $table) {
            $table->string('client_event_id', 100)->nullable()->after('tracking_token');
            $table->unique(['trackable_type', 'trackable_id', 'client_event_id'], 'checkpoint_updates_trackable_client_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('checkpoint_updates', function (Blueprint $table) {
            $table->dropUnique('checkpoint_updates_trackable_client_event_unique');
            $table->dropColumn('client_event_id');
        });
    }
};
