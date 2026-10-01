<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Referral commission payouts (owner decision: payable via PayPal).
 *
 * - `referral_payout_profiles`: where a referrer wants to be paid. One row per
 *   user; the PayPal email is stored encrypted (PII) and only ever shown masked.
 * - `referral_payouts`: one row per payout ATTEMPT for one ReferralEarning
 *   (App\Services\Referrals\ReferralPayoutService). A PayPal attempt is
 *   requested by one admin and approved by a DIFFERENT admin before PayPal is
 *   called; a manual attempt (MoMo / bank) is recorded as already succeeded
 *   with a mandatory reference note.
 *
 * Never pay twice: the partial unique index allows at most ONE live
 * (requested / processing / unclaimed) or succeeded attempt per earning, so a
 * second request, a manual mark-paid racing a PayPal payout, or a double
 * approval all fail at the database. Failed / rejected attempts stay as
 * history and the earning becomes payable again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_payout_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('paypal_email')->nullable();
            $table->timestampsTz();
        });

        Schema::create('referral_payouts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('referral_earning_id')->constrained('referral_earnings')->cascadeOnDelete();
            $table->string('method', 16);
            $table->string('status', 20);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->text('receiver_email')->nullable();
            $table->string('sender_batch_id', 64)->nullable()->unique();
            $table->string('payout_batch_id', 64)->nullable()->index();
            $table->string('payout_item_id', 64)->nullable()->index();
            $table->string('transaction_id', 64)->nullable();
            $table->string('provider_status', 32)->nullable();
            $table->text('reference_note')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->jsonb('provider_payload')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'method']);
        });

        DB::statement("ALTER TABLE referral_payouts ADD CONSTRAINT referral_payouts_method_check CHECK (method IN ('paypal','manual'))");
        DB::statement("ALTER TABLE referral_payouts ADD CONSTRAINT referral_payouts_status_check CHECK (status IN ('requested','rejected','processing','succeeded','unclaimed','failed'))");
        DB::statement("CREATE UNIQUE INDEX referral_payouts_one_live_per_earning ON referral_payouts (referral_earning_id) WHERE status IN ('requested','processing','unclaimed','succeeded')");
        DB::statement("CREATE UNIQUE INDEX referral_payouts_one_success_per_earning ON referral_payouts (referral_earning_id) WHERE status = 'succeeded'");
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_payouts');
        Schema::dropIfExists('referral_payout_profiles');
    }
};
