<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Partial composite index backing GET /api/v1/nearby's bounding-box
 * pre-filter. Only rows that actually have coordinates are indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS companies_lat_lng_index ON companies (latitude, longitude) WHERE latitude IS NOT NULL AND longitude IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS companies_lat_lng_index');
    }
};
