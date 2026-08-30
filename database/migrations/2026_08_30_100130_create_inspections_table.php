<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspection report (blueprint §27). Immutable once finalised (finalised_at
 * set) except through the formal amendment workflow -- see
 * inspection_amendments and App\Models\Inspection::amend()/finalise().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('inspector_id')->constrained('inspectors')->restrictOnDelete();
            $table->foreignId('timber_lot_id')->nullable()->constrained('timber_lots')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('inspection_type', 40);
            $table->date('scheduled_for')->nullable();
            $table->timestampTz('performed_at')->nullable();
            $table->string('location', 255)->nullable();
            $table->decimal('observed_quantity', 14, 2)->nullable();
            $table->jsonb('measured_dimensions')->default('{}');
            $table->jsonb('moisture_results')->default('{}');
            $table->text('quality_findings')->nullable();
            $table->text('species_findings')->nullable();
            $table->text('packaging_findings')->nullable();
            $table->jsonb('photos')->default('[]');
            $table->jsonb('videos')->default('[]');
            $table->jsonb('documents_examined')->default('[]');
            $table->string('result', 20)->nullable();
            $table->text('inspector_notes')->nullable();
            $table->string('digital_signature', 512)->nullable();
            $table->timestampTz('finalised_at')->nullable();
            $table->timestampsTz();

            $table->index('timber_lot_id');
            $table->index('inspector_id');
        });

        DB::statement("ALTER TABLE inspections ADD CONSTRAINT inspections_type_check CHECK (inspection_type IN ('pre_production','pre_shipment','loading','quantity','moisture','species_identification','quality_grade','packaging','document_cross_check','site'))");
        DB::statement("ALTER TABLE inspections ADD CONSTRAINT inspections_result_check CHECK (result IN ('pass','fail','conditional'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
