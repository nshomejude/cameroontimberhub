<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence submitted by either party during a Dispute's Evidence Submission
 * stage. Mirrors OrderDocument's storage pattern: files land on the PRIVATE
 * `documents` disk and are never web-served directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_evidence', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by_company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->text('description');

            $table->string('disk', 40)->default('documents')->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->timestamps();

            $table->index('dispute_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_evidence');
    }
};
