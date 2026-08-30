<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared payment-attempt ledger across every gateway (MTN Mobile Money,
 * Orange Money, Stripe, PayPal). One row per payment attempt against a
 * company's Plan subscription. Deliberately gateway-agnostic: `provider`
 * says which one, `provider_reference` holds that gateway's own
 * transaction/order id, `metadata` holds whatever raw response payload is
 * worth keeping for support/audit, and nothing here assumes any particular
 * gateway's request/response shape.
 *
 * No credentials live in this table or anywhere in the codebase — each
 * provider reads its own API keys from config (env-backed), left as
 * placeholders until real keys are supplied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('provider_reference', 191)->nullable();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'status']);
            $table->index(['provider', 'provider_reference']);
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_check CHECK (provider IN ('mtn_momo','orange_money','stripe','paypal'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending','completed','failed','refunded','cancelled'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_currency_check CHECK (currency IN ('XAF','USD','EUR','GBP','CNY'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
