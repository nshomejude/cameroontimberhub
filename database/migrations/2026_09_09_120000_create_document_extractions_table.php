<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI-assisted document extraction (blueprint §35). One row per extraction
 * attempt against a CompanyDocument or OrderDocument ("subject" — polymorphic
 * so either can be re-extracted over time without a schema change).
 *
 * This is a REVIEW AID ONLY: extracted_fields is never read by any
 * verification/compliance decision path. An admin/compliance officer looks
 * at the extraction and either corrects the underlying document fields
 * themselves or dismisses it — reviewed_by/reviewed_at just records that a
 * human looked, not that the extraction was trusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();

            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->string('extraction_status', 20)->default('pending');
            $table->jsonb('extracted_fields')->nullable();
            $table->text('error_note')->nullable();

            $table->string('ai_provider', 40)->nullable();
            $table->string('model_used', 80)->nullable();
            $table->timestampTz('extracted_at')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();

            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE document_extractions ADD CONSTRAINT document_extractions_status_check CHECK (extraction_status IN ('pending','completed','failed','needs_review'))");
        DB::statement('CREATE INDEX document_extractions_fields_gin ON document_extractions USING gin (extracted_fields)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};
