<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/PRICE_DATA_STANDARD.md §3 — company_species reaches "full basis".
 * Additive only; all nullable. `unit` is added because `min_order_m3` bakes
 * m3 into the column name and blocks area-priced forms (veneer/flooring).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_species', function (Blueprint $table) {
            $table->string('moisture_content', 60)->nullable()->after('grade');
            $table->string('dimensions', 255)->nullable()->after('moisture_content');
            $table->string('unit', 10)->nullable()->after('dimensions');
            $table->string('basis', 12)->nullable()->after('unit');
            $table->string('region', 120)->nullable()->after('basis');
        });
    }

    public function down(): void
    {
        Schema::table('company_species', function (Blueprint $table) {
            $table->dropColumn(['moisture_content', 'dimensions', 'unit', 'basis', 'region']);
        });
    }
};
