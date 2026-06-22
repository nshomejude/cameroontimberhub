<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('species', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 180)->unique();
            $table->string('common_name', 150);
            $table->string('scientific_name', 180)->nullable();
            $table->jsonb('local_names')->nullable();
            $table->jsonb('trade_names')->nullable();
            $table->string('family', 120)->nullable();
            $table->text('description')->nullable();
            $table->jsonb('characteristics')->nullable();
            $table->boolean('is_cites_listed')->default(false);
            $table->string('cites_appendix', 5)->nullable();
            $table->string('image_path', 512)->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->boolean('is_published')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index('is_published');
            $table->index('is_cites_listed');
        });

        DB::statement("ALTER TABLE species ADD CONSTRAINT species_cites_appendix_check CHECK (cites_appendix IS NULL OR cites_appendix IN ('I','II','III'))");

        // Weighted full-text vector (generated, immutable: all text inputs).
        DB::statement("ALTER TABLE species ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('english', coalesce(common_name, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(scientific_name, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(description, '')), 'C')
        ) STORED");

        DB::statement('CREATE INDEX species_search_vector_gin ON species USING gin (search_vector)');
        DB::statement('CREATE INDEX species_common_name_trgm ON species USING gin (common_name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('species');
    }
};
