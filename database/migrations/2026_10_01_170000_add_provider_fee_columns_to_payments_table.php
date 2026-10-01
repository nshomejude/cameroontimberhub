<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment-provider fee accounting (PayPal commission structure, part A).
 *
 * Additive + nullable so existing rows stay valid:
 *  - `base_amount`         — what the platform is owed for the purchase
 *                            (plan price, incl. any tax), i.e. `amount` minus
 *                            a fee passed through to the payer. Null on
 *                            pre-existing rows; read it via
 *                            Payment::baseAmount(), which falls back to
 *                            `amount`.
 *  - `provider_fee_amount` — the provider's processing fee for this charge,
 *                            whoever bears it.
 *  - `provider_fee_bearer` — 'buyer' (passed through: included in `amount`)
 *                            or 'platform' (absorbed: a platform cost).
 *
 * `amount` keeps its meaning: exactly what the payer is charged, which is
 * what every gateway sends to the provider and checks the capture against.
 *
 * `payment_settings` gains nullable fee_* override columns: when set they win
 * over the env defaults in config/payments.php (App\Services\Payments\ProviderFees).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('base_amount', 14, 2)->nullable()->after('amount');
            $table->decimal('provider_fee_amount', 14, 2)->nullable()->after('base_amount');
            $table->string('provider_fee_bearer', 20)->nullable()->after('provider_fee_amount');
        });

        Schema::table('payment_settings', function (Blueprint $table) {
            $table->decimal('fee_percent', 6, 3)->nullable();
            $table->decimal('fee_fixed', 10, 2)->nullable();
            $table->string('fee_bearer', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['base_amount', 'provider_fee_amount', 'provider_fee_bearer']);
        });

        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn(['fee_percent', 'fee_fixed', 'fee_bearer']);
        });
    }
};
