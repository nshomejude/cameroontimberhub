<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('type', 40)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->jsonb('document_snapshot')->nullable();
            $table->jsonb('requested_badges')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('assigned_to');
        });

        DB::statement("ALTER TABLE verification_requests ADD CONSTRAINT verification_requests_status_check CHECK (status IN ('pending','in_review','approved','rejected'))");
        DB::statement("CREATE INDEX verification_requests_queue_idx ON verification_requests (status) WHERE status IN ('pending','in_review')");
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_requests');
    }
};
