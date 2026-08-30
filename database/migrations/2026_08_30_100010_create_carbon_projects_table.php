<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal carbon project model: a carbon-developer company's reforestation
 * / avoided-deforestation project, informational only -- no credit ledger
 * or trading behind estimated_credits_per_year, just a stated estimate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carbon_projects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('project_type', 30);
            $table->string('region', 150)->nullable();
            $table->decimal('area_hectares', 12, 2)->nullable();
            $table->decimal('estimated_credits_per_year', 14, 2)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampsTz();

            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE carbon_projects ADD CONSTRAINT carbon_projects_type_check CHECK (project_type IN ('reforestation','afforestation','avoided_deforestation','agroforestry'))");
        DB::statement("ALTER TABLE carbon_projects ADD CONSTRAINT carbon_projects_status_check CHECK (status IN ('draft','active','archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('carbon_projects');
    }
};
