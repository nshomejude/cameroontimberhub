<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Species Data Governance fields (implementation blueprint §77).
 *
 * `family` (botanical family), and `authoritative_sources` (citation list —
 * serving the blueprint's "Reference Sources" need with a richer
 * url/publisher/title/accessed_date shape) already exist on this table from
 * earlier migrations, so they are intentionally not re-added here.
 *
 * `synonyms` is distinct from the existing `local_names` (Cameroon
 * vernacular names) and `trade_names` (commercial trade names) — it covers
 * other taxonomic/common synonyms a supplier might type.
 *
 * `commercial_categories` is a plural, free-form tag list (e.g. "hardwood",
 * "decorative", "structural") distinct from the existing singular
 * `commercial_category` enum (the MINFOF-adjacent market class).
 *
 * `country_presence` is the countries a species is found in, distinct from
 * the existing `region_availability` (Cameroon regions only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->jsonb('synonyms')->default('[]')->after('local_names');
            $table->jsonb('commercial_categories')->default('[]')->after('commercial_category');
            $table->jsonb('country_presence')->default('[]')->after('region_availability');
            $table->date('last_reviewed_at')->nullable()->after('authoritative_sources');
        });
    }

    public function down(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->dropColumn(['synonyms', 'commercial_categories', 'country_presence', 'last_reviewed_at']);
        });
    }
};
