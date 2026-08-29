<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structured production/service capacity (brief §4.3, gap-plan 1.5.4):
 * {capability, quantity, unit, period}, e.g. "Kiln drying 100 m3/month".
 * Polymorphic owner from day one -- only Company populates it today, but
 * this mirrors every other primitive built this session (Document,
 * Verification, Consent) so a future Processor/Artisan-specific model
 * doesn't need a migration to participate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('owner_type', 120);
            $table->unsignedBigInteger('owner_id');
            $table->string('capability', 150);
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 30);
            $table->string('period', 20);
            $table->timestampsTz();

            $table->index(['owner_type', 'owner_id']);
            $table->index('capability');
        });

        DB::statement("ALTER TABLE capacities ADD CONSTRAINT capacities_period_check CHECK (period IN ('day','week','month','quarter','year'))");
        DB::statement('ALTER TABLE capacities ADD CONSTRAINT capacities_quantity_positive CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('capacities');
    }
};
