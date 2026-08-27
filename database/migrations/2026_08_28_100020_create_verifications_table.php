<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');
            $table->string('stage', 30)->default('registered');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->index(['entity_type', 'entity_id']);
            $table->index('assigned_to');
        });

        DB::statement("ALTER TABLE verifications ADD CONSTRAINT verifications_stage_check CHECK (stage IN ('registered','company_info','business_docs','identity_kyc','forestry_legal_docs','compliance_review','verified','rejected','published'))");

        // One OPEN (non-terminal) verification per entity. Terminal stages
        // (rejected, published) are excluded from the partial unique index so
        // a rejected entity can register a fresh Verification and try again,
        // and a published one is simply done. Mirrors the
        // verification_requests_queue_idx partial-index pattern this table
        // deliberately does not touch.
        DB::statement("CREATE UNIQUE INDEX verifications_open_per_entity_idx ON verifications (entity_type, entity_id) WHERE stage NOT IN ('rejected','published')");
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications');
    }
};
