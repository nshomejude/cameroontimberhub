<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('original_filename', 255);
            $table->string('storage_path', 512);
            $table->string('disk', 40)->default('documents');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('visibility', 20)->default('private');
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->jsonb('sigif_fields')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'document_type_id']);
            $table->index('visibility');
        });

        DB::statement("ALTER TABLE company_documents ADD CONSTRAINT company_documents_status_check CHECK (status IN ('pending','approved','rejected','needs_correction'))");
        DB::statement("ALTER TABLE company_documents ADD CONSTRAINT company_documents_visibility_check CHECK (visibility IN ('private','admin_only','buyer_visible','public'))");
        DB::statement("CREATE INDEX company_documents_pending_idx ON company_documents (status) WHERE status = 'pending' AND deleted_at IS NULL");
        DB::statement('CREATE INDEX company_documents_expiry_idx ON company_documents (expiry_date) WHERE expiry_date IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE INDEX company_documents_sigif_gin ON company_documents USING gin (sigif_fields)');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_documents');
    }
};
