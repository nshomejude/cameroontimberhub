<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_checkpoints', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('verification_id')->constrained('verifications')->cascadeOnDelete();
            $table->string('stage', 30);
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index('verification_id');
            $table->index(['verification_id', 'stage']);
        });

        DB::statement("ALTER TABLE verification_checkpoints ADD CONSTRAINT verification_checkpoints_stage_check CHECK (stage IN ('registered','company_info','business_docs','identity_kyc','forestry_legal_docs','compliance_review','verified','rejected','published'))");
        DB::statement("ALTER TABLE verification_checkpoints ADD CONSTRAINT verification_checkpoints_status_check CHECK (status IN ('pending','approved','rejected','needs_more_info'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_checkpoints');
    }
};
