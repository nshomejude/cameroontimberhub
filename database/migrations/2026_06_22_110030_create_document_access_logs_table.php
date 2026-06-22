<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_document_id')->constrained('company_documents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20)->default('view');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            // Append-only event log: created_at only, no updated_at.
            $table->timestampTz('created_at')->nullable();

            $table->index('company_document_id');
            $table->index('user_id');
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE document_access_logs ADD CONSTRAINT document_access_logs_action_check CHECK (action IN ('view','download','signed_url_issued'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_logs');
    }
};
