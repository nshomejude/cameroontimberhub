<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commission collection (owner decision 2026-10-01: commission is collected
 * from suppliers by manual MoMo / bank deposit against MONTHLY statements).
 *
 * `orders.commission_charged_at` records WHEN `CommissionCalculator::charge()`
 * snapshotted the commission, so `commission:issue-statements` can bill
 * "every order whose commission was charged in that month". Additive and
 * nullable; orders charged before this column existed are backfilled from
 * their supplier-confirmation time (the moment charge() runs), falling back
 * to `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestampTz('commission_charged_at')->nullable()->after('commission_credited_amount');
            $table->index(['is_commission_charged', 'commission_charged_at']);
        });

        DB::table('orders')
            ->where('is_commission_charged', true)
            ->whereNull('commission_charged_at')
            ->update(['commission_charged_at' => DB::raw('COALESCE(confirmed_at, updated_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['is_commission_charged', 'commission_charged_at']);
            $table->dropColumn('commission_charged_at');
        });
    }
};
