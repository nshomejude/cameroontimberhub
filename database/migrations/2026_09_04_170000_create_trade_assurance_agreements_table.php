<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §28 "Trade Assurance Phase 1": coordination/tracking only.
 *
 * This is explicitly NOT escrow or fund custody -- the blueprint cautions
 * that real fund custody requires a licensed financial partner and is out of
 * scope for now. A TradeAssuranceAgreement is a structured way for the buyer
 * and supplier on an Order to agree on milestones and track their
 * completion; `amount_share_percent` on a milestone is informational only
 * and is never wired to any payment execution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_assurance_agreements', function (Blueprint $table) {
            $table->id();

            // One agreement per order, enforced by the unique index below.
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX trade_assurance_agreements_order_unique ON trade_assurance_agreements (order_id)');

        Schema::create('trade_assurance_milestones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trade_assurance_agreement_id')
                ->constrained('trade_assurance_agreements')
                ->cascadeOnDelete();

            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->date('expected_completion_date')->nullable();

            $table->string('status', 20)->default('pending');

            // Informational share of the order value this milestone
            // represents. NEVER wired to any payment gateway or fund
            // movement -- display/coordination only, per blueprint §28.
            $table->decimal('amount_share_percent', 5, 2)->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();

            $table->timestampsTz();

            $table->index(['trade_assurance_agreement_id', 'sequence']);
        });

        DB::statement("ALTER TABLE trade_assurance_milestones ADD CONSTRAINT trade_assurance_milestones_status_check CHECK (status IN ('pending','in_progress','buyer_confirmed','disputed','released'))");
        DB::statement('ALTER TABLE trade_assurance_milestones ADD CONSTRAINT trade_assurance_milestones_share_check CHECK (amount_share_percent IS NULL OR (amount_share_percent >= 0 AND amount_share_percent <= 100))');
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_assurance_milestones');
        Schema::dropIfExists('trade_assurance_agreements');
    }
};
