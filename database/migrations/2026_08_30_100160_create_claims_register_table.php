<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Internal Claims Register for marketing content (implementation blueprint
 * §48). Every material public claim (supplier counts, RFQ volumes,
 * environmental/compliance/certification claims, etc.) should be recorded
 * here with its evidence source, owner, and a review cadence, so an
 * unsubstantiated claim can't quietly land on a public page again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims_register', function (Blueprint $table) {
            $table->id();
            $table->text('claim_text');
            $table->string('page_location', 255)->nullable();
            $table->text('evidence_source')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('approved_at')->nullable();
            $table->date('review_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE claims_register ADD CONSTRAINT claims_register_status_check CHECK (status IN ('draft','approved','needs_review','retired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('claims_register');
    }
};
