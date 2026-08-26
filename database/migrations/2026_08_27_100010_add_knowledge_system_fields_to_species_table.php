<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Species Knowledge System fields — spec §C
 * (docs/superpowers/specs/2026-08-26-seo-ai-authority-architecture.md).
 *
 * Every field is nullable with no default value. A null `eudr_risk_note` is
 * not "unknown data we forgot to fill in" — it is the honest state until a
 * genuinely sourced, dated regulatory assessment exists, exactly like the
 * existing `log_export_status = unknown` default on this table. Nothing here
 * is backfilled with placeholder or inferred content by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->string('french_name', 150)->nullable()->after('scientific_name');
            $table->jsonb('taxonomy')->nullable()->after('family');
            $table->text('workability')->nullable()->after('characteristics');
            $table->text('drying_behaviour')->nullable()->after('workability');
            $table->jsonb('treatments')->nullable()->after('drying_behaviour');
            $table->jsonb('grades_available')->nullable()->after('treatments');
            $table->text('eudr_risk_note')->nullable()->after('log_export_status');
            $table->jsonb('authoritative_sources')->nullable()->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->dropColumn([
                'french_name', 'taxonomy', 'workability', 'drying_behaviour',
                'treatments', 'grades_available', 'eudr_risk_note', 'authoritative_sources',
            ]);
        });
    }
};
