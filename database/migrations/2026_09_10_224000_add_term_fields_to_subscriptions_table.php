<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine Phase 1, task M3 (docs/superpowers/plans/2026-09-10-billing-engine.md).
 *
 * Additive term/lifecycle columns on `subscriptions`. Every column is nullable
 * so the existing manual-assignment rows keep validating. `price_amount` /
 * `price_currency` are a FROZEN snapshot of what was paid for this term — a
 * later change to the plan's list price must never rewrite them (spec §2:
 * "historical invoices/subscriptions never change because a price changes
 * later").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_period', 20)->nullable()->after('status');
            $table->timestampTz('renews_at')->nullable()->after('ends_at');
            $table->timestampTz('trial_ends_at')->nullable()->after('renews_at');
            $table->timestampTz('grace_until')->nullable()->after('trial_ends_at');
            $table->foreignId('payment_id')->nullable()->after('assigned_by')
                ->constrained('payments')->nullOnDelete();
            $table->string('provider_reference', 190)->nullable()->after('payment_id');
            $table->decimal('price_amount', 14, 2)->nullable()->after('provider_reference');
            $table->char('price_currency', 3)->nullable()->after('price_amount');

            $table->index(['company_id', 'status', 'renews_at']);
        });

        // The lifecycle needs two more statuses beyond the original
        // active/expired/cancelled CHECK constraint.
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_status_check');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('active','trialing','past_due','expired','cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_status_check');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('active','expired','cancelled'))");

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status', 'renews_at']);
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn([
                'billing_period', 'renews_at', 'trial_ends_at', 'grace_until',
                'provider_reference', 'price_amount', 'price_currency',
            ]);
        });
    }
};
