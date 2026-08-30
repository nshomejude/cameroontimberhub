<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §17 Compliance Workflow: a case opened against any owning
 * entity (Company today; TimberLot, Shipment as those need assessment).
 * Polymorphic owner_type/owner_id mirrors the dominant convention already
 * established by Capacity, Certificate, and Document (owner_type/owner_id),
 * not Verification's outlier entity_type/entity_id. The model relation is
 * still named entity() per the task spec, independent of the column names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_cases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('owner_type', 120);
            $table->unsignedBigInteger('owner_id');
            $table->char('country_code', 2)->nullable();
            $table->string('status', 30)->default('not_assessed');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('opened_at')->useCurrent();
            $table->timestampTz('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['owner_type', 'owner_id']);
        });

        DB::statement("ALTER TABLE compliance_cases ADD CONSTRAINT compliance_cases_status_check CHECK (status IN ('not_assessed','incomplete','under_review','ready_for_further_review','conditionally_ready','high_risk','remediation_required','approved_for_cth_workflow','expired','suspended'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_cases');
    }
};
