<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §15 Compliance Rule Engine: a configurable rule row instead of
 * hard-coded compliance checklists in screens. A null dimension column
 * (market/country_code/product_category/etc.) is a wildcard — it applies
 * regardless of that dimension. See ComplianceRule::scopeApplicableTo().
 *
 * regulatory_source_id is restrictOnDelete: the blueprint's §16 rule is "no
 * compliance requirement without a source" — enforced here at the schema
 * level, not just convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('regulatory_source_id')->constrained('regulatory_sources')->restrictOnDelete();
            $table->string('market', 120)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('product_category', 120)->nullable();
            $table->string('commodity_code', 60)->nullable();
            $table->string('supplier_type', 60)->nullable();
            $table->string('transaction_type', 60)->nullable();
            $table->string('regulatory_framework', 120);
            $table->jsonb('required_evidence')->default('[]');
            $table->jsonb('optional_evidence')->default('[]');
            $table->jsonb('risk_factors')->default('[]');
            $table->text('decision_rules')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('review_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('country_code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_rules');
    }
};
