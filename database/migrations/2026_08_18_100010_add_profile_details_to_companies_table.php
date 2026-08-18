<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier-profile detail columns: the business-summary, forest-sourcing and
 * logistics facts the approved /companies/{slug} mockup surfaces.
 *
 * Every column is nullable and every panel on the profile hides itself when it
 * has no real value behind it — nothing here is ever defaulted or invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('tagline', 160)->nullable()->after('trade_name');
            $table->smallInteger('on_time_delivery_percent')->nullable()->after('response_time_hours');
            $table->string('payment_terms', 255)->nullable()->after('on_time_delivery_percent');
            $table->string('working_hours', 120)->nullable()->after('payment_terms');
            $table->string('main_ports', 255)->nullable()->after('working_hours');
            $table->string('shipping_terms', 120)->nullable()->after('main_ports');
            $table->smallInteger('delivery_days_min')->nullable()->after('shipping_terms');
            $table->smallInteger('delivery_days_max')->nullable()->after('delivery_days_min');
            $table->string('forest_location', 255)->nullable()->after('delivery_days_max');
            $table->string('forest_management', 255)->nullable()->after('forest_location');
            $table->decimal('annual_harvest_capacity_m3', 14, 2)->nullable()->after('forest_management');
        });

        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_on_time_delivery_check CHECK (on_time_delivery_percent IS NULL OR (on_time_delivery_percent BETWEEN 0 AND 100))');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_delivery_days_check CHECK ((delivery_days_min IS NULL OR delivery_days_min >= 0) AND (delivery_days_max IS NULL OR delivery_days_max >= 0) AND (delivery_days_min IS NULL OR delivery_days_max IS NULL OR delivery_days_max >= delivery_days_min))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_on_time_delivery_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_delivery_days_check');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'tagline', 'on_time_delivery_percent', 'payment_terms', 'working_hours',
                'main_ports', 'shipping_terms', 'delivery_days_min', 'delivery_days_max',
                'forest_location', 'forest_management', 'annual_harvest_capacity_m3',
            ]);
        });
    }
};
