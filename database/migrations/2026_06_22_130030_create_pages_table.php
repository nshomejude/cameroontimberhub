<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 200)->unique();
            $table->string('title', 255);
            $table->string('h1', 255)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->jsonb('data')->nullable();
            $table->jsonb('schema_json')->nullable();
            $table->string('canonical_url', 512)->nullable();
            $table->string('template', 30)->default('static');
            $table->boolean('is_published')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('is_published');
            $table->index('template');
        });

        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_template_check CHECK (template IN ('static','landing','programmatic','legal'))");
        DB::statement("ALTER TABLE pages ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(h1, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(meta_description, '')), 'B')
        ) STORED");
        DB::statement('CREATE INDEX pages_search_vector_gin ON pages USING gin (search_vector)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
