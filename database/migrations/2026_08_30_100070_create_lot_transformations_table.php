<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mass-balance / transformation ledger (implementation blueprint §11):
 * distinct from the lot_events activity log. A `lot_transformations` row
 * is a single processing event (e.g. "72 m3 of sawn timber came out of
 * these two log lots, with 28 m3 lost"), linked to its input and output
 * TimberLots via the two many-to-many pivot tables below — a
 * transformation can consume multiple input lots and produce multiple
 * output lots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_transformations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('processor_company_id')->constrained('companies')->restrictOnDelete();
            $table->string('transformation_type', 60);
            $table->decimal('input_volume_m3', 14, 3);
            $table->decimal('output_volume_m3', 14, 3);
            $table->decimal('loss_volume_m3', 14, 3);
            $table->decimal('transformation_ratio', 6, 4)->nullable();
            $table->timestampTz('processed_at');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index('processor_company_id');
            $table->index('transformation_type');
            $table->index('processed_at');
        });

        Schema::create('lot_transformation_inputs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('lot_transformation_id')->constrained('lot_transformations')->cascadeOnDelete();
            $table->foreignId('timber_lot_id')->constrained('timber_lots')->restrictOnDelete();
            $table->decimal('quantity_m3', 14, 3);
            $table->timestampsTz();

            $table->index('timber_lot_id');
        });

        Schema::create('lot_transformation_outputs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('lot_transformation_id')->constrained('lot_transformations')->cascadeOnDelete();
            $table->foreignId('timber_lot_id')->constrained('timber_lots')->restrictOnDelete();
            $table->decimal('quantity_m3', 14, 3);
            $table->timestampsTz();

            $table->index('timber_lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_transformation_outputs');
        Schema::dropIfExists('lot_transformation_inputs');
        Schema::dropIfExists('lot_transformations');
    }
};
