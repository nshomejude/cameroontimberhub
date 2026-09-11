<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine M8 (plan §19, §124) — admin-configurable coupons.
 *
 * A coupon is either a `percent` discount (value stored as a fraction, e.g.
 * 0.20 = 20%, mirroring TaxRule) or a `fixed` amount in a specific
 * `currency`. Redemption bookkeeping (`redemptions_count`) lives on this
 * row for a fast cap check, but the authoritative per-redemption history is
 * `coupon_redemptions` — this table is never rewritten to "fix" history.
 *
 * NOT wired into checkout yet (billing engine plan explicitly scopes M8 to
 * the data model + admin CRUD + a pure calculation service; checkout
 * integration is a documented follow-up — see CouponCalculator docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('type', 10); // 'percent' | 'fixed'
            $table->decimal('value', 14, 4); // fraction for percent (0.20), decimal amount for fixed
            $table->char('currency', 3)->nullable(); // required when type=fixed, null when type=percent
            $table->jsonb('applies_to_segments')->nullable(); // array of Plan.segment strings; null = all
            $table->jsonb('applies_to_plan_ids')->nullable(); // array of plan ids; null = all plans within segment filter
            $table->unsignedInteger('max_redemptions')->nullable(); // null = unlimited
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->unsignedInteger('max_redemptions_per_company')->nullable()->default(1);
            $table->boolean('stacks_with_annual')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['is_active', 'valid_from', 'valid_until']);
        });

        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_check CHECK (type IN ('percent','fixed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
