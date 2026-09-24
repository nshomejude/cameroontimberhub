<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A small dedicated table rather than reusing `Page` (title/slug/data/
 * schema_json — a generic CMS page body) or `Article` (a blog post with an
 * author/category). Neither fits: an announcement needs a scheduling window
 * (`starts_at`/`ends_at`), a `sort_order` for the feed, and a mobile-app CTA
 * that targets an in-app screen id (`cta_screen`, a 3-digit code like "047"),
 * not a web URL — nothing existing models that shape, and bolting it onto
 * `Page` would drag along slug/data/schema_json fields an announcement has
 * no use for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('image_path')->nullable();
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_screen', 3)->nullable(); // mobile app screen id, e.g. "047"
            $table->string('cta_reference')->nullable(); // optional id/slug the target screen needs
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['is_active', 'starts_at', 'ends_at', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
