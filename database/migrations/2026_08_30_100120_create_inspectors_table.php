<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspector onboarding profile (blueprint §26): an Inspector is a User with
 * an extra profile carrying identity verification, credentials, coverage,
 * and eligibility state. Eligibility to be assigned inspections requires
 * identity_verified_at + agreement_accepted_at + status = 'active' -- see
 * App\Models\Inspector::scopeEligible().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspectors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('organisation_name', 255)->nullable();
            $table->timestampTz('identity_verified_at')->nullable();
            $table->jsonb('professional_credentials')->default('[]');
            $table->text('conflict_of_interest_declaration')->nullable();
            $table->jsonb('coverage_regions')->default('[]');
            $table->jsonb('inspection_categories')->default('[]');
            $table->smallInteger('years_experience')->nullable();
            $table->timestampTz('agreement_accepted_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampsTz();

            $table->index('user_id');
            $table->index('status');
        });

        DB::statement("ALTER TABLE inspectors ADD CONSTRAINT inspectors_status_check CHECK (status IN ('pending','active','suspended','deactivated'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('inspectors');
    }
};
