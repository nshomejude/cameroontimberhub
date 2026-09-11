<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine Phase 3, task M6 (docs/superpowers/plans/2026-09-10-billing-engine.md).
 *
 * Opt-in free trial length, per plan. 0 (the default, so every existing plan
 * is unaffected) means "no trial offered". Per §7.5 a trial is pull-model:
 * full entitlements for `trial_days` days, no pre-authorisation, one per
 * company lifetime, converts when the customer pays before it ends else the
 * renewal job lapses it to the segment Free plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->default(0)->after('billing_period');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('trial_days');
        });
    }
};
