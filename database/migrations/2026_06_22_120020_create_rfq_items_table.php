<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('species_id')->nullable()->constrained('species')->nullOnDelete();
            $table->string('species_text', 180)->nullable();
            $table->string('form', 20)->nullable();
            $table->string('grade', 60)->nullable();
            $table->string('dimensions', 255)->nullable();
            $table->decimal('quantity', 14, 2)->nullable();
            $table->string('unit', 10)->nullable();
            $table->string('moisture_content', 60)->nullable();
            $table->timestampsTz();

            $table->index('rfq_id');
            $table->index('species_id');
        });

        DB::statement("ALTER TABLE rfq_items ADD CONSTRAINT rfq_items_form_check CHECK (form IS NULL OR form IN ('logs','sawn','veneer','plywood','other'))");
        DB::statement("ALTER TABLE rfq_items ADD CONSTRAINT rfq_items_unit_check CHECK (unit IS NULL OR unit IN ('m3','ton','pcs','container'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_items');
    }
};
