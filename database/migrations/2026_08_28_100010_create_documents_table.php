<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A single, reusable document store for any owning entity — Species today;
 * carbon projects, sponsorship files, vehicles and drivers as those entities
 * are built (see docs/GAP_PLAN.md items 2.x, 6.x, 1.5.x).
 *
 * Deliberately does NOT replace `company_documents` or `order_documents` --
 * both are live subsystems with 24+ consumers each; migrating them here is
 * tracked separately (docs/GAP_PLAN.md item 0.1b) rather than done silently
 * alongside building this table.
 *
 * `hash`/`prev_hash` chain documents in upload order *per owner*, giving the
 * integrity-record primitive the brief's trust layer (§3.9) needs -- and
 * this is the one piece of that primitive gap-plan item 0.5 doesn't have to
 * build again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('owner_type', 120);
            $table->unsignedBigInteger('owner_id');
            $table->string('type', 60);
            $table->string('original_filename', 255);
            $table->string('disk', 40)->default('documents');
            $table->string('storage_path', 512);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            // Reserved for a SHA-256 of the uploaded file's raw bytes, to be
            // computed by whatever future task actually handles the upload
            // (verifies file-content integrity). Nothing populates this yet.
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('issuer', 190)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('verification_status', 20)->default('unverified');
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // NOT the file checksum (see checksum_sha256 above). These hash
            // row metadata (owner, filename, storage path, previous hash,
            // timestamp) purely to chain documents in per-owner upload
            // order -- see Document::booted().
            $table->char('hash', 64)->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['owner_type', 'owner_id']);
            $table->index('verification_status');
        });

        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_verification_status_check CHECK (verification_status IN ('unverified','verified','rejected','needs_correction'))");
        DB::statement('CREATE INDEX documents_expiry_idx ON documents (expires_at) WHERE expires_at IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
