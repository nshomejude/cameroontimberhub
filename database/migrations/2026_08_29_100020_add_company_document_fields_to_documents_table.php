<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive extension of `documents` to support gap-plan item 0.1b (migrating
 * CompanyDocument's ~28 consumers onto the polymorphic Document store)
 * without losing three CompanyDocument-only fields that have no equivalent
 * on `documents`: `document_type_id`, `visibility`, `sigif_fields`.
 *
 * Does NOT touch the original 2026_08_28_100010_create_documents_table.php
 * migration, and does not rename/alter anything item 0.8's certificate work
 * already reads (hash/prev_hash/checksum_sha256/verification_status/etc).
 * All three new columns are nullable: existing Document rows (Species,
 * Product owners) never had a document type/visibility/SIGIF payload and
 * must not be forced to backfill one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('document_type_id')->nullable()->after('type')->constrained('document_types')->nullOnDelete();
            $table->string('visibility', 20)->nullable()->after('verification_status');
            $table->jsonb('sigif_fields')->nullable()->after('visibility');
        });

        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_visibility_check CHECK (visibility IN ('private','admin_only','buyer_visible','public'))");
        DB::statement('CREATE INDEX documents_sigif_gin ON documents USING gin (sigif_fields)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_sigif_gin');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_visibility_check');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_type_id');
            $table->dropColumn(['visibility', 'sigif_fields']);
        });
    }
};
