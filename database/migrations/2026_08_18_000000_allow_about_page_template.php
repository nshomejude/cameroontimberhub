<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The About page now has its own marketing layout (public/pages/about.blade.php),
 * so `about` becomes a valid value for pages.template alongside the existing set.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pages DROP CONSTRAINT IF EXISTS pages_template_check');
        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_template_check CHECK (template IN ('static','about','landing','programmatic','legal'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE pages SET template = 'static' WHERE template = 'about'");
        DB::statement('ALTER TABLE pages DROP CONSTRAINT IF EXISTS pages_template_check');
        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_template_check CHECK (template IN ('static','landing','programmatic','legal'))");
    }
};
