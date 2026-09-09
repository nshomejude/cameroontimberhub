<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `occurred_at` to checkpoint_updates (blueprint §45-46: offline field
 * capture for logistics/drivers). Additive only — the original table
 * (2026_08_29_150010_create_checkpoint_updates_table.php) is untouched.
 *
 * Why a separate column from `created_at`: a driver recording a checkpoint
 * offline (rural roads, border crossings, no connectivity) queues it in
 * OfflineQueue and it may only reach the server minutes or hours later, and
 * checkpoints can arrive OUT OF ORDER relative to when they actually
 * happened (e.g. a "delivered" checkpoint synced over a lucky signal burst
 * before an earlier-queued "dispatched" checkpoint finally syncs).
 *
 * `created_at` always reflects real server-receipt order (monotonic, never
 * backdated) — that stays the audit trail of *when the platform learned
 * about* a checkpoint. `occurred_at` carries the client-reported timestamp
 * of when the event actually happened in the field, and is what
 * HasCheckpointUpdates::latestCheckpoint() now orders by to derive "current
 * status" — so a late-arriving-but-earlier-occurring checkpoint can never
 * regress what's shown as current. See App\Models\CheckpointUpdate (default
 * fill on create) and App\Models\Concerns\HasCheckpointUpdates.
 *
 * Nullable + defaulted to `created_at` for any row that doesn't set it
 * explicitly (see CheckpointUpdate::booted()), so this is fully backward
 * compatible with every pre-existing call site/test that never mentions
 * `occurred_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkpoint_updates', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('checkpoint_updates', function (Blueprint $table) {
            $table->dropColumn('occurred_at');
        });
    }
};
