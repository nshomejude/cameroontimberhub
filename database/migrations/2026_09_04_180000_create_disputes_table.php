<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formal Dispute Resolution workflow (blueprint §64): a real lifecycle case
 * opened against an Order, additive to (not a replacement for) the existing
 * lightweight "dispute" contact-form category in ContactController.
 *
 * The buyer side of an order is a plain user (`orders.user_id`), not
 * necessarily a company member (EnsureBuyerAccount keeps buyers and company
 * members in separate populations), so party identity is recorded per side
 * as a user_id (always present) plus an optional company_id (present when
 * that side is acting as a company, e.g. the supplier). Authorization never
 * trusts these columns alone -- DisputeService re-derives party membership
 * from the order's own relationships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('category', 20);
            $table->string('status', 40)->default('opened');
            $table->text('description');

            $table->foreignId('raised_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('raised_by_company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->foreignId('respondent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('respondent_company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->text('resolution_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        DB::statement("ALTER TABLE disputes ADD CONSTRAINT disputes_category_check CHECK (category IN ('quality','quantity','delay','payment','other'))");
        DB::statement("ALTER TABLE disputes ADD CONSTRAINT disputes_status_check CHECK (status IN ('opened','evidence_pending','counterparty_response_pending','under_review','resolved','appealed','closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
