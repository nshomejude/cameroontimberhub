<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            // Nullable: a supplier may quote a substitute that maps to no
            // specific RFQ line.
            $table->foreignId('rfq_item_id')->nullable()->constrained('rfq_items')->nullOnDelete();
            $table->foreignId('species_id')->nullable()->constrained('species')->nullOnDelete();
            $table->string('description', 255);
            $table->string('form', 20)->nullable();
            $table->string('grade', 60)->nullable();
            $table->string('dimensions', 255)->nullable();
            $table->decimal('quantity', 14, 2);
            $table->string('unit', 10);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index('quote_id');
            $table->index('rfq_item_id');
            $table->index('species_id');
        });

        DB::statement("ALTER TABLE quote_items ADD CONSTRAINT quote_items_form_check CHECK (form IS NULL OR form IN ('logs','sawn','veneer','plywood','other'))");
        DB::statement("ALTER TABLE quote_items ADD CONSTRAINT quote_items_unit_check CHECK (unit IN ('m3','ton','pcs','container'))");
        DB::statement('ALTER TABLE quote_items ADD CONSTRAINT quote_items_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND line_total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};
