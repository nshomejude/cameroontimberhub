<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine M7 (plan §15, §124) — marketplace commission rate table.
 *
 * The ONLY place a commission rate lives. Nothing in code hard-codes the
 * §15 percentages; a `commission_rules` row is the single source of truth,
 * admin-editable with no deploy. `segment` mirrors `Plan.segment` (null =
 * every segment); `plan_tier` is matched against `Plan.slug` as a free
 * string (e.g. "free", "starter", "pro", "enterprise") rather than a FK, so
 * a rule can target a tier without depending on any one segment's exact
 * slug set — null = every tier in the segment.
 *
 * Historical immutability (plan §2): a rate change never mutates a past
 * order's commission. Enforced by (a) `orders.commission_rate` /
 * `commission_amount` being a frozen snapshot taken at charge time and
 * (b) `CommissionRule` forbidding an in-place rate/cap edit on a rule that
 * was already active — supersede with a NEW rule with a later
 * `effective_from` instead (mirrors `TaxRule`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('segment', 60)->nullable(); // null = all segments; else a Plan.segment value
            $table->string('plan_tier', 60)->nullable(); // null = all tiers; else matched against Plan.slug
            $table->decimal('domestic_rate', 6, 4);
            $table->decimal('international_rate', 6, 4);
            $table->decimal('cap_amount', 14, 2)->nullable();
            $table->decimal('cap_percent', 6, 4)->nullable();
            $table->boolean('is_active')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['segment', 'is_active', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
