<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // Provenance only. Every commercial value below is a copy taken at
            // award time; nothing on this row is ever re-read from the quote.
            $table->foreignId('quote_item_id')->nullable()->constrained('quote_items')->nullOnDelete();
            $table->foreignId('species_id')->nullable()->constrained('species')->nullOnDelete();
            $table->string('species_name', 255)->nullable();
            $table->string('description', 255);
            $table->string('form', 20)->nullable();
            $table->string('grade', 60)->nullable();
            $table->string('dimensions', 255)->nullable();
            $table->decimal('quantity', 14, 2);
            $table->string('unit', 10);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestampsTz();

            $table->index('order_id');
            $table->index('species_id');
        });

        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_form_check CHECK (form IS NULL OR form IN ('logs','sawn','veneer','plywood','other'))");
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_unit_check CHECK (unit IN ('m3','ton','pcs','container'))");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND line_total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
