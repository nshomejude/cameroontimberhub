<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier trust metrics surfaced by the "Supplier Information" card on the
 * product detail page: buyer rating, completed order volume, typical response
 * time and the languages the supplier's trade desk works in.
 *
 * Every column is nullable. The card renders a stat only when a real value
 * exists — it never falls back to a zero or an invented figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('rating_avg', 2, 1)->nullable()->after('years_experience');
            $table->integer('rating_count')->nullable()->after('rating_avg');
            $table->integer('orders_completed')->nullable()->after('rating_count');
            $table->smallInteger('response_time_hours')->nullable()->after('orders_completed');
            $table->jsonb('languages')->nullable()->after('response_time_hours');
        });

        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_rating_avg_check CHECK (rating_avg IS NULL OR (rating_avg >= 0 AND rating_avg <= 5))');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_rating_count_check CHECK (rating_count IS NULL OR rating_count >= 0)');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_orders_completed_check CHECK (orders_completed IS NULL OR orders_completed >= 0)');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_response_time_check CHECK (response_time_hours IS NULL OR (response_time_hours > 0 AND response_time_hours <= 720))');
    }

    public function down(): void
    {
        foreach (['rating_avg', 'rating_count', 'orders_completed', 'response_time'] as $c) {
            DB::statement("ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_{$c}_check");
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['rating_avg', 'rating_count', 'orders_completed', 'response_time_hours', 'languages']);
        });
    }
};
