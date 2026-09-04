<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot linking a Shipment (gap-plan 1.5.10) to the TimberLot(s) it is
 * carrying, so the shipment/lot-event ledger (blueprint §10) can be wired
 * together. `quantity_m3` is nullable: a lot may be split across several
 * shipments or only partially shipped, but not every caller will know or
 * need to record the split amount up front.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_timber_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timber_lot_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_m3', 14, 3)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_timber_lots');
    }
};
