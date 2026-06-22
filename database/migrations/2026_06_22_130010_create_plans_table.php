<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('price_amount', 14, 2)->default(0);
            $table->char('price_currency', 3)->default('XAF');
            $table->string('billing_period', 20)->default('monthly');
            $table->jsonb('features')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['is_active', 'sort_order']);
        });

        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_currency_check CHECK (price_currency IN ('XAF','USD','EUR','GBP','CNY'))");
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_billing_period_check CHECK (billing_period IN ('monthly','yearly','once'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
