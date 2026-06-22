<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_species', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('species_id')->constrained('species')->cascadeOnDelete();
            $table->string('form', 20)->nullable();
            $table->string('grade', 60)->nullable();
            $table->decimal('min_order_m3', 14, 2)->nullable();
            $table->decimal('price_amount', 14, 2)->nullable();
            $table->char('price_currency', 3)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->unique(['company_id', 'species_id']);
            $table->index('species_id');
        });

        DB::statement("ALTER TABLE company_species ADD CONSTRAINT company_species_form_check CHECK (form IS NULL OR form IN ('logs','sawn','veneer','plywood','other'))");
        DB::statement("ALTER TABLE company_species ADD CONSTRAINT company_species_currency_check CHECK (price_currency IS NULL OR price_currency IN ('XAF','USD','EUR','GBP','CNY'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_species');
    }
};
