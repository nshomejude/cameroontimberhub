<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a referrer wants commissions paid when PayPal is not an option
 * (owner decision: XAF commissions are paid manually by Mobile Money or bank
 * transfer). Free text — a MoMo number or bank details — stored encrypted
 * like `paypal_email` and only ever shown masked to the referrer; finance
 * reads it in full on the admin Referral earnings table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_payout_profiles', function (Blueprint $table) {
            $table->text('manual_payout_details')->nullable()->after('paypal_email');
        });
    }

    public function down(): void
    {
        Schema::table('referral_payout_profiles', function (Blueprint $table) {
            $table->dropColumn('manual_payout_details');
        });
    }
};
