<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge Centre hub assignment — spec §B.
 *
 * Nullable on purpose and with no default. A null hub is not missing data: it
 * marks the article as short-form news served at /insights/{slug}, which spec
 * §B keeps deliberately distinct from evergreen /knowledge content. Nothing is
 * backfilled here; Task 4 moves the two existing evergreen articles explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('hub', 40)->nullable()->after('category');
            $table->index(['hub', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['hub', 'published_at']);
            $table->dropColumn('hub');
        });
    }
};
