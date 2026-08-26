<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipts are the platform's issued, verifiable record of an order.
     *
     * A receipt is NOT a proof of payment — this platform has no payment
     * integration. It records the order as awarded on the platform, for the
     * amount stated, on the date stated, and can be checked by any third party
     * holding the number or the token.
     */
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('receipt_number', 40)->unique();
            // Unguessable. Never sequential, never derived from the number: the
            // number is printed on the document, the token is what a QR/deep
            // link carries, so leaking one must not yield the other.
            $table->string('verification_token', 64)->unique();
            $table->timestampTz('issued_at');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            // Verification telemetry. Counting checks is useful signal and
            // exposes nothing about the buyer.
            $table->timestampTz('verified_at')->nullable();
            $table->unsignedInteger('verification_count')->default(0);
            // Set when a receipt is administratively voided (e.g. issued in
            // error). A voided receipt still verifies — as VOID, not authentic.
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestampsTz();

            $table->index('order_id');
        });

        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_currency_check CHECK (currency IN ('XAF','USD','EUR','GBP','CNY'))");
        DB::statement('ALTER TABLE receipts ADD CONSTRAINT receipts_amount_check CHECK (amount >= 0)');
        // One live receipt per order. Voided receipts are excluded so a
        // corrected receipt can be re-issued without deleting history.
        DB::statement('CREATE UNIQUE INDEX receipts_live_per_order_unique ON receipts (order_id) WHERE voided_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
