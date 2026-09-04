<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §33-34 Market Intelligence: a daily point-in-time snapshot of a
 * computed index value, kept so later trend charts do not need to recompute
 * history from raw orders/quotes/RFQs every time.
 *
 * `dimension` is the slice the value was computed over -- a species id (as a
 * string), a company id (as a string), or the literal 'overall'. `metadata`
 * carries whatever supporting figures the index computed (sample size,
 * currency, etc.) so a chart can show provenance without re-querying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_intelligence_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->string('index_type'); // price_index | demand_index | supplier_performance_index
            $table->string('dimension'); // species id, company id, or 'overall'
            $table->decimal('value', 18, 4)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['index_type', 'dimension', 'snapshot_date']);
        });

        DB::statement(
            "ALTER TABLE market_intelligence_snapshots ADD CONSTRAINT market_intelligence_snapshots_index_type_check ".
            "CHECK (index_type IN ('price_index', 'demand_index', 'supplier_performance_index'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('market_intelligence_snapshots');
    }
};
