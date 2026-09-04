<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geospatial provenance (blueprint §12): a plot/harvest-area BOUNDARY, not
 * just the existing single origin_latitude/origin_longitude point. Stored
 * as portable jsonb holding a GeoJSON Polygon —
 * {"type":"Polygon","coordinates":[[[lng,lat],...]]} — deliberately not a
 * PostGIS geometry column/extension, to avoid infra risk. Validated on the
 * model side via App\Support\GeoJsonPolygon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timber_lots', function (Blueprint $table) {
            $table->jsonb('origin_boundary')->nullable()->after('origin_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('timber_lots', function (Blueprint $table) {
            $table->dropColumn('origin_boundary');
        });
    }
};
