<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 60)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('requires_expiry')->default(false);
            $table->boolean('affects_verification')->default(true);
            $table->boolean('supports_sigif')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
