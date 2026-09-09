<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §35 AI Compliance Assistant: an audit log of every question
 * asked, who asked it, exactly which ComplianceRule/RegulatorySource rows
 * were retrieved to ground the answer, and the answer text returned. This
 * is the highest-stakes AI feature on the platform (wrong answers carry
 * real legal/reputational consequences for suppliers) so every exchange
 * must be reviewable later by a human, not just logged for debugging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_assistant_queries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('asked_by')->constrained('users')->cascadeOnDelete();
            $table->text('question');
            $table->char('country_code', 2)->nullable();
            $table->string('product_category', 120)->nullable();
            $table->jsonb('grounding_rule_ids')->default('[]');
            $table->jsonb('grounding_source_ids')->default('[]');
            $table->text('answer');
            $table->boolean('was_grounded')->default(false);
            $table->boolean('ai_was_ready')->default(true);
            $table->timestampsTz();

            $table->index('asked_by');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_assistant_queries');
    }
};
