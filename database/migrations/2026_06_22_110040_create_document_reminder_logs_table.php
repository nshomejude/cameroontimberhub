<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_reminder_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_document_id')->constrained('company_documents')->cascadeOnDelete();
            $table->string('threshold', 10);
            $table->timestampTz('sent_at');
            $table->timestampsTz();

            $table->unique(['company_document_id', 'threshold']);
        });

        DB::statement("ALTER TABLE document_reminder_logs ADD CONSTRAINT document_reminder_logs_threshold_check CHECK (threshold IN ('90','60','30','expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reminder_logs');
    }
};
