<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRICING_SPEC §15 caps every marketplace fee at "$5,000 / N% whichever is
 * lower" — a fixed cap denominated in USD, while orders are priced in XAF,
 * USD, EUR… `cap_amount` alone carried no currency, so `CommissionCalculator`
 * compared a bare number against the order currency (5,000 XAF ≈ $8).
 *
 * `cap_currency` says what currency `cap_amount` is expressed in. Null keeps
 * the original meaning (cap in the order's own currency), so every existing
 * rule behaves exactly as before. Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->string('cap_currency', 3)->nullable()->after('cap_amount');
        });
    }

    public function down(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->dropColumn('cap_currency');
        });
    }
};
