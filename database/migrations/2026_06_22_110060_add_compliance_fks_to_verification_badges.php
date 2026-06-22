<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The verification_badges table was created in Phase B with two FK columns left
 * unconstrained because their target tables (verification_requests,
 * company_documents) did not yet exist. Phase C now adds those foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_badges', function (Blueprint $table) {
            $table->foreign('verification_request_id')->references('id')->on('verification_requests')->nullOnDelete();
            $table->foreign('supporting_document_id')->references('id')->on('company_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('verification_badges', function (Blueprint $table) {
            $table->dropForeign(['verification_request_id']);
            $table->dropForeign(['supporting_document_id']);
        });
    }
};
