<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('materials_used', 255)->nullable();
            $table->string('finish', 120)->nullable();
            $table->string('dimensions_description', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['materials_used', 'finish', 'dimensions_description']);
        });
    }
};
