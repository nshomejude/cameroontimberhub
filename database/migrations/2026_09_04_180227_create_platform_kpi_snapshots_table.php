<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §66-69 Platform Operations: one row per calendar day holding the
 * North-Star KPI figures computed by PlatformKpiService, so later trend
 * charts can read history without recomputing it from raw companies/orders
 * every time. `date` is unique so the daily snapshot command can upsert
 * idempotently instead of accumulating duplicate rows on re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_kpi_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->jsonb('metrics');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_kpi_snapshots');
    }
};
