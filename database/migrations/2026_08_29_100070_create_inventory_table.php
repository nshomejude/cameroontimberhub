<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product/location "available now" quantity (brief §4, gap-plan 1.5.6),
 * decremented atomically on order via InventoryService::reserve().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('location', 150)->nullable();
            $table->decimal('quantity_available', 12, 2)->default(0);
            $table->string('unit', 20);
            $table->timestampsTz();

            $table->index(['product_id', 'location']);
        });

        DB::statement('ALTER TABLE inventory ADD CONSTRAINT inventory_quantity_non_negative CHECK (quantity_available >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
