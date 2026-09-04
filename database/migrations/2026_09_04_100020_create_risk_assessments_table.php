<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §7 Supplier Risk Engine: a structured, multi-dimension risk
 * score, appended (never overwritten) per computation so a company builds
 * up a history/trend over time. Admin/staff-facing only — see
 * RiskAssessment::computeFor() for what each dimension is actually
 * derived from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->smallInteger('identity_risk');
            $table->smallInteger('documentation_risk');
            $table->smallInteger('forestry_origin_risk');
            $table->smallInteger('traceability_risk');
            $table->smallInteger('product_risk');
            $table->smallInteger('delivery_risk');
            $table->smallInteger('transaction_risk');
            $table->smallInteger('dispute_risk');
            $table->smallInteger('compliance_risk');
            $table->smallInteger('reputation_risk');

            $table->smallInteger('composite_score');
            $table->string('risk_band', 20);

            $table->timestampTz('computed_at');
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->index('company_id');
        });

        // Value-range and enum-style guards (Postgres CHECK constraints).
        foreach ([
            'identity_risk', 'documentation_risk', 'forestry_origin_risk', 'traceability_risk',
            'product_risk', 'delivery_risk', 'transaction_risk', 'dispute_risk',
            'compliance_risk', 'reputation_risk', 'composite_score',
        ] as $column) {
            DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_{$column}_range CHECK ({$column} BETWEEN 0 AND 100)");
        }

        DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_risk_band_check CHECK (risk_band IN ('low_concern', 'moderate', 'elevated', 'high', 'critical'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
