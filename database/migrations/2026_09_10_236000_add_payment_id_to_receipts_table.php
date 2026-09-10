<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine M11 — a Receipt is issued on a completed plan Payment, not
 * only on an awarded Order. This makes the link real:
 *
 *  - `order_id` becomes nullable (a subscription receipt has no order),
 *  - `payment_id` is a new nullable FK to `payments`,
 *  - one live (non-void) receipt per payment, mirroring the per-order rule.
 *
 * Additive and backwards-compatible: existing order receipts keep their
 * `order_id` and a NULL `payment_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->change();
            $table->foreignId('payment_id')->nullable()->after('order_id')
                ->constrained('payments')->nullOnDelete();
            $table->index('payment_id');
        });

        DB::statement('CREATE UNIQUE INDEX receipts_live_per_payment_unique ON receipts (payment_id) WHERE payment_id IS NOT NULL AND voided_at IS NULL');
        DB::statement('ALTER TABLE receipts ADD CONSTRAINT receipts_order_or_payment_check CHECK (order_id IS NOT NULL OR payment_id IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE receipts DROP CONSTRAINT IF EXISTS receipts_order_or_payment_check');
        DB::statement('DROP INDEX IF EXISTS receipts_live_per_payment_unique');

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });
    }
};
