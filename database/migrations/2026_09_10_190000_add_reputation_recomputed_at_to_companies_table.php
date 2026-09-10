<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production-readiness plan Task C1 — reputation recompute job.
 *
 * `ReputationService::recompute()` derives `response_rate_percent`,
 * `on_time_delivery_percent` and `orders_completed` from real order / RFQ
 * rows (they were static/seeded before). Two additive, nullable columns
 * support that:
 *
 *  - `reputation_recomputed_at` — when the figures were last rebuilt, so the
 *    public profile can say "reputation as of <date>" rather than implying
 *    the numbers are live.
 *  - `disputes_count` — count of formal disputes where the company is the
 *    respondent, cached here so a supplier card / profile need not join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestampTz('reputation_recomputed_at')->nullable()->after('on_time_delivery_percent');
            $table->unsignedInteger('disputes_count')->default(0)->after('reputation_recomputed_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['reputation_recomputed_at', 'disputes_count']);
        });
    }
};
