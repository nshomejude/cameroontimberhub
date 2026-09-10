<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamper-evident hash chain over issued receipts (production-readiness plan
 * Task B3, brief §1.2). Mirrors the activity-log chain
 * (2026_08_28_100040_add_hash_chain_to_activity_log_table) applied to
 * receipts: one global chain in issue order (issued_at, then id), so that
 * altering, deleting or reordering any historical receipt row breaks every
 * hash after it -- detectable by `receipts:verify-chain`.
 *
 * Additive and nullable: existing rows are backfilled in issue order by the
 * idempotent `receipts:backfill-integrity-chain` command, and rows written
 * before the backfill runs simply carry NULL until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->char('hash', 64)->nullable()->after('void_reason');
            $table->char('prev_hash', 64)->nullable()->after('hash');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn(['hash', 'prev_hash']);
        });
    }
};
