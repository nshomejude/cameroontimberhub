<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine Phase 3, task M6 (docs/superpowers/plans/2026-09-10-billing-engine.md).
 *
 * `subscriptions:process-renewals` sends the "your plan renews in 7 days"
 * reminder exactly once per term. This nullable timestamp is the idempotency
 * marker — set when the reminder goes out, cleared on the next activation
 * (a fresh term deserves a fresh reminder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestampTz('renewal_reminded_at')->nullable()->after('grace_until');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('renewal_reminded_at');
        });
    }
};
