<?php

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editorial articles — the /insights content platform.
 *
 * `body` stores MARKDOWN, not HTML. Reasons, in order of weight:
 *   1. The bulk import path is markdown files with YAML frontmatter, so storing
 *      markdown keeps file and row byte-identical and makes re-import a real
 *      idempotency check rather than a lossy round-trip.
 *   2. Rendering happens at read time through Laravel's bundled CommonMark with
 *      HTML input escaped, so a compromised or careless editor cannot inject
 *      script into a public page — an HTML column would be a stored-XSS surface.
 *   3. Headings stay machine-parseable, which is what the table of contents and
 *      the internal-link pass rely on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 200)->unique();
            $table->string('title', 255);
            $table->string('h1', 255)->nullable();
            $table->text('excerpt')->nullable();
            $table->text('body')->nullable();
            $table->string('category', 40);
            $table->string('hero_image_path', 512)->nullable();

            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->jsonb('keywords')->nullable();
            $table->jsonb('faqs')->nullable();
            $table->jsonb('sources')->nullable();

            $table->smallInteger('reading_minutes')->nullable();
            $table->string('status', 20)->default(ArticleStatus::Draft->value);
            $table->timestampTz('published_at')->nullable();

            // E-E-A-T: the organisation is the default author. The person
            // fields stay null until a real, named human with real credentials
            // is attached — they are never fabricated.
            $table->string('author_name', 150)->nullable();
            $table->string('author_role', 150)->nullable();

            $table->jsonb('related_species_ids')->nullable();
            $table->jsonb('related_product_types')->nullable();

            // Watermarks for the importer's overwrite rule.
            //
            // `source_synced_at` is when `articles:import` last wrote this row;
            // it is compared against `updated_at` ("has a human saved since?"),
            // and both live in the same database time domain so the comparison
            // is apples to apples.
            //
            // `source_mtime` is the source file's mtime as a raw Unix second,
            // compared against the file on disk ("has the file changed?"). It
            // is deliberately NOT a timestamp column: a filesystem mtime and a
            // timestamptz round-tripped through the session time zone are two
            // different clocks, and comparing them silently mis-fires.
            $table->timestampTz('source_synced_at')->nullable();
            $table->bigInteger('source_mtime')->nullable();

            $table->timestampsTz();

            $table->index('status');
            $table->index('category');
            $table->index('published_at');
            $table->index(['status', 'published_at']);
        });

        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_category_check CHECK (category IN ('".implode("','", ArticleCategory::values())."'))");
        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_status_check CHECK (status IN ('".implode("','", ArticleStatus::values())."'))");

        // A published article must have a publication date — the Article
        // JSON-LD and the sitemap both depend on it being present.
        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_published_at_check CHECK (status <> 'published' OR published_at IS NOT NULL)");

        // Weighted FTS vector, mirroring `species`.
        DB::statement("ALTER TABLE articles ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(excerpt, '')), 'B') ||
            setweight(to_tsvector('english', coalesce(body, '')), 'C')
        ) STORED");

        DB::statement('CREATE INDEX articles_search_vector_gin ON articles USING gin (search_vector)');
        DB::statement('CREATE INDEX articles_title_trgm ON articles USING gin (title gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
