<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The public Cameroon timber glossary (/knowledge/glossary).
 *
 * Deliberately flat — a dictionary, not an article system. Related terms and
 * species are stored as id arrays and resolved at render time, mirroring
 * Article::relatedSpecies(). Search uses the same weighted FTS vector +
 * pg_trgm pattern as species/articles/products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glossary_terms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 180)->unique();
            $table->string('term', 150);
            $table->string('french_term', 150)->nullable();
            $table->text('definition');
            $table->text('explanation')->nullable();
            $table->jsonb('related_term_ids')->nullable();
            $table->jsonb('related_species_ids')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->boolean('is_published')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index('is_published');
            $table->index('term');
        });

        // Weighted FTS vector, mirroring species/articles.
        DB::statement("ALTER TABLE glossary_terms ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('english', coalesce(term, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(definition, '')), 'B') ||
            setweight(to_tsvector('english', coalesce(explanation, '')), 'C')
        ) STORED");

        DB::statement('CREATE INDEX glossary_terms_search_vector_gin ON glossary_terms USING gin (search_vector)');
        DB::statement('CREATE INDEX glossary_terms_term_trgm ON glossary_terms USING gin (term gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('glossary_terms');
    }
};
