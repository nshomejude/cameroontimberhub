<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/PRICE_DATA_STANDARD.md §3 — additive basis dimensions that are
 * buildable today: moisture on quote/order lines, basis+region on products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->string('moisture_content', 60)->nullable()->after('dimensions');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('moisture_content', 60)->nullable()->after('dimensions');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('basis', 12)->nullable();
            $table->string('region', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropColumn('moisture_content');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('moisture_content');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['basis', 'region']);
        });
    }
};
