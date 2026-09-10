<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine M5 (plan §7.3, §20) — configurable tax engine.
 *
 * The ONLY place a tax rate lives. Nothing in code hard-codes Cameroon TVA
 * (19.25%) or any other rate; a `tax_rules` row is the single source of
 * truth, admin-editable with no deploy (plan §124). Ships with the Cameroon
 * rule seeded but `is_active = false` until DGI registration is confirmed.
 *
 * Historical immutability (plan §2): a rate change never mutates a past
 * invoice/subscription. Enforced by (a) Subscription.price_amount being a
 * frozen snapshot (M3) and (b) TaxRule forbidding an in-place `rate` edit on
 * an active rule — you create a NEW rule with a later `effective_from`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);                 // e.g. "Cameroon TVA"
            $table->string('jurisdiction', 8);           // ISO country code (CM) or "*" = rest-of-world default
            $table->decimal('rate', 6, 4);               // fraction, e.g. 0.1925 = 19.25%
            $table->string('applies_to', 60)->nullable(); // null = all; else a Plan.segment value
            $table->boolean('is_active')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['jurisdiction', 'is_active', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rules');
    }
};
