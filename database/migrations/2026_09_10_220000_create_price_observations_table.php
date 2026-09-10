<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/PRICE_DATA_STANDARD.md §2 — write-time derivative price row.
 *
 * Not a source of truth for commercial data: every value is copied from an
 * existing price-bearing row (quote_item / order_item / …) at the moment a
 * real commercial event happens, and `origin_type`/`origin_id` point back to
 * that row. This task only lands the schema and the first two collectors
 * (transacted, quoted); PriceBand computation and any public surfacing are
 * §2.11, gated on a CEMAC competition-law review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_observations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('species_id')->nullable()->constrained('species')->nullOnDelete();
            $table->string('product_type')->nullable();

            $table->string('source'); // transacted | quoted | listed | reference | logistics | processing
            $table->decimal('unit_price', 14, 2);
            $table->char('currency', 3);
            $table->string('unit');
            $table->string('basis')->nullable();
            $table->string('region')->nullable();
            $table->decimal('quantity', 14, 2)->nullable();
            $table->string('volume_band')->nullable();
            $table->timestampTz('observed_at');

            // Nullable polymorphic pointer back to the row this was derived
            // from (an Order or a Quote today).
            $table->string('origin_type')->nullable();
            $table->unsignedBigInteger('origin_id')->nullable();

            $table->timestampsTz();

            $table->index(['species_id', 'source', 'observed_at']);
            $table->index(['product_type', 'source', 'observed_at']);
            $table->index(['origin_type', 'origin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_observations');
    }
};
