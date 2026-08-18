<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cameroon commercial classification + technical properties for species.
 *
 * `commercial_category` is a MARKET grouping, not a legal one. `log_export_status`
 * defaults to 'unknown' and is informational only — the real schedules are set by
 * MINFOF policy and must be verified before being shown as guidance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->string('commercial_category', 40)->nullable()->after('family');
            $table->boolean('is_promoted')->default(false)->after('commercial_category');
            $table->string('log_export_status', 20)->nullable()->default('unknown')->after('is_promoted');
            $table->integer('density_kg_m3_min')->nullable()->after('log_export_status');
            $table->integer('density_kg_m3_max')->nullable()->after('density_kg_m3_min');
            $table->string('durability_class', 60)->nullable()->after('density_kg_m3_max');
            $table->integer('janka_hardness')->nullable()->after('durability_class');
            $table->jsonb('typical_uses')->nullable()->after('janka_hardness');
            $table->jsonb('region_availability')->nullable()->after('typical_uses');

            $table->index('commercial_category');
            $table->index('is_promoted');
            $table->index('log_export_status');
        });

        DB::statement("ALTER TABLE species ADD CONSTRAINT species_commercial_category_check CHECK (commercial_category IS NULL OR commercial_category IN ('primary_hardwood','promoted_species','secondary_hardwood','softwood','specialty'))");
        DB::statement("ALTER TABLE species ADD CONSTRAINT species_log_export_status_check CHECK (log_export_status IS NULL OR log_export_status IN ('permitted','restricted','banned','unknown'))");
        DB::statement('ALTER TABLE species ADD CONSTRAINT species_density_range_check CHECK (density_kg_m3_min IS NULL OR density_kg_m3_max IS NULL OR density_kg_m3_min <= density_kg_m3_max)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE species DROP CONSTRAINT IF EXISTS species_commercial_category_check');
        DB::statement('ALTER TABLE species DROP CONSTRAINT IF EXISTS species_log_export_status_check');
        DB::statement('ALTER TABLE species DROP CONSTRAINT IF EXISTS species_density_range_check');

        Schema::table('species', function (Blueprint $table) {
            $table->dropColumn([
                'commercial_category',
                'is_promoted',
                'log_export_status',
                'density_kg_m3_min',
                'density_kg_m3_max',
                'durability_class',
                'janka_hardness',
                'typical_uses',
                'region_availability',
            ]);
        });
    }
};
