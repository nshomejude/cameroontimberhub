<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping documents and proof of delivery attached to an order.
 *
 * Files land on the PRIVATE `documents` disk (see config/filesystems.php) and
 * are never web-served: `storage_path` is not reachable from the public root,
 * and the only way out is OrderDocumentDownloadController, which re-checks that
 * the requester is a participant on the order's conversation.
 *
 * `kind` is a closed CHECK constraint for the same reason the message type is —
 * the enum and the database must not drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind', 40);
            $table->string('label', 160)->nullable();

            $table->string('disk', 40)->default('documents');
            $table->string('storage_path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->nullable();

            $table->timestamps();

            $table->index(['order_id', 'kind']);
        });

        DB::statement("ALTER TABLE order_documents ADD CONSTRAINT order_documents_kind_check CHECK (kind IN ('proof_of_delivery','commercial_invoice','packing_list','bill_of_lading','certificate','other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_documents');
    }
};
