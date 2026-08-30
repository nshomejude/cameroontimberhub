<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Timber Lot as a first-class object (implementation blueprint §8):
 * every serious supply item becomes a structured lot rather than a generic
 * product listing. A lot may optionally reference the Product it was
 * listed under (nullable — a lot can exist before/without ever becoming a
 * public listing), and always belongs to a Company and (usually) a Species.
 *
 * `lot_number` is the blueprint's human-readable identifier format, e.g.
 * "CTH-TIM-2026-000184" — generated in the model, not here.
 *
 * Deliberately scoped to the blueprint's "minimum attributes" list, using
 * existing enums/columns where this schema already has an equivalent
 * (ProductType for product form, PriceUnit for volume/price units) rather
 * than inventing parallel vocabularies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timber_lots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('lot_number', 40)->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('species_id')->nullable()->constrained('species')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_form', 40)->nullable();
            $table->string('grade', 60)->nullable();
            $table->string('dimensions', 120)->nullable();
            $table->decimal('quantity', 14, 2)->nullable();
            $table->decimal('volume_m3', 14, 3)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('moisture_content', 60)->nullable();
            $table->string('drying_method', 60)->nullable();
            $table->string('processing_method', 60)->nullable();
            $table->char('origin_country', 2)->default('CM');
            $table->string('origin_region', 120)->nullable();
            $table->string('origin_forest_source', 255)->nullable();
            $table->string('harvest_block_reference', 120)->nullable();
            $table->decimal('origin_latitude', 9, 6)->nullable();
            $table->decimal('origin_longitude', 9, 6)->nullable();
            $table->date('harvest_period_start')->nullable();
            $table->date('harvest_period_end')->nullable();
            $table->string('processing_site', 255)->nullable();
            $table->string('processing_batch', 120)->nullable();
            $table->decimal('available_quantity', 14, 2)->nullable();
            $table->decimal('reserved_quantity', 14, 2)->default(0);
            $table->string('price_basis', 60)->nullable();
            $table->char('price_currency', 3)->nullable();
            $table->string('incoterm', 10)->nullable();
            $table->string('destination', 120)->nullable();
            $table->string('lead_time', 60)->nullable();
            $table->string('certification_status', 40)->nullable();
            $table->string('legality_evidence_status', 40)->default('not_assessed');
            $table->string('traceability_status', 40)->default('not_traceable');
            $table->string('inspection_status', 40)->default('not_inspected');
            $table->string('status', 30)->default('draft');
            $table->timestampsTz();

            $table->index(['company_id', 'status']);
            $table->index('species_id');
            $table->index('lot_number');
        });

        DB::statement("ALTER TABLE timber_lots ADD CONSTRAINT timber_lots_status_check CHECK (status IN ('draft','available','reserved','partially_allocated','sold','in_processing','in_transit','delivered','released','quarantined','disputed','cancelled','archived'))");
        DB::statement("ALTER TABLE timber_lots ADD CONSTRAINT timber_lots_legality_check CHECK (legality_evidence_status IN ('not_assessed','incomplete','under_review','ready','remediation_required','expired'))");
        DB::statement("ALTER TABLE timber_lots ADD CONSTRAINT timber_lots_traceability_check CHECK (traceability_status IN ('not_traceable','partial','traceable','fully_traceable'))");
        DB::statement("ALTER TABLE timber_lots ADD CONSTRAINT timber_lots_inspection_check CHECK (inspection_status IN ('not_inspected','scheduled','passed','failed','conditional'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('timber_lots');
    }
};
