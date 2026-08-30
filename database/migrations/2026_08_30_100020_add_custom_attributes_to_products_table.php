<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function ($table) {
            $table->jsonb('custom_attributes')->nullable()->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('products', function ($table) {
            $table->dropColumn('custom_attributes');
        });
    }
};
