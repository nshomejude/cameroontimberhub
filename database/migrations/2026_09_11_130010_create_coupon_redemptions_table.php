<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine M8 — one immutable row per coupon redemption.
 *
 * `amount_discounted`/`currency` are a snapshot at redeem time (plan §2 —
 * a later coupon/rate edit must never alter a past redemption's numbers).
 * `payment_id` is nullable because checkout integration is deferred; once
 * wired, redeem() will pass the payment id it was applied to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->decimal('amount_discounted', 14, 2);
            $table->char('currency', 3);
            $table->timestampTz('redeemed_at');
            $table->timestampsTz();

            $table->index(['coupon_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
