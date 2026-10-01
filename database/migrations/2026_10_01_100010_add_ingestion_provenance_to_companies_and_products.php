<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agent Ingestion Gateway provenance (docs/api/AGENT_INGESTION.md): where
     * a supplier/product record came from, which key wrote it, the agent's
     * evidence, and whether a human still has to review it. All nullable /
     * defaulted — human-created rows are untouched.
     */
    public function up(): void
    {
        foreach (['companies', 'products'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('source', 80)->nullable();
                $table->string('external_id', 191)->nullable();
                $table->text('source_url')->nullable();
                $table->unsignedBigInteger('ingested_by_token_id')->nullable();
                $table->timestampTz('ingested_at')->nullable();
                $table->jsonb('ingestion_meta')->nullable();
                $table->boolean('needs_review')->default(false);

                $table->index('needs_review');
                $table->index('source');
            });
        }

        DB::statement('CREATE UNIQUE INDEX companies_source_external_id_unique ON companies (source, external_id) WHERE external_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX products_company_source_external_id_unique ON products (company_id, source, external_id) WHERE external_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS companies_source_external_id_unique');
        DB::statement('DROP INDEX IF EXISTS products_company_source_external_id_unique');

        foreach (['companies', 'products'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['needs_review']);
                $table->dropIndex(['source']);
                $table->dropColumn(['source', 'external_id', 'source_url', 'ingested_by_token_id', 'ingested_at', 'ingestion_meta', 'needs_review']);
            });
        }
    }
};
