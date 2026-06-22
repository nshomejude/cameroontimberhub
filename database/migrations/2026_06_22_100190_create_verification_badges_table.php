<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_badges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // FK to verification_requests added in Phase C (table not yet created).
            $table->unsignedBigInteger('verification_request_id')->nullable();
            $table->string('badge_type', 40);
            $table->string('status', 20)->default('active');
            $table->timestampTz('issued_at')->useCurrent();
            $table->date('valid_until')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_notes')->nullable();
            // FK to company_documents added in Phase C (table not yet created).
            $table->unsignedBigInteger('supporting_document_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_public')->default(true);
            $table->string('reference_code', 40)->unique();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index(['company_id', 'badge_type']);
        });

        DB::statement("ALTER TABLE verification_badges ADD CONSTRAINT verification_badges_badge_type_check CHECK (badge_type IN ('verified_company','verified_exporter','sigif_registered','legal_timber_supplier','export_ready','cites_approved','sustainability_profile','premium_member'))");
        DB::statement("ALTER TABLE verification_badges ADD CONSTRAINT verification_badges_status_check CHECK (status IN ('active','revoked','expired'))");
        DB::statement("CREATE UNIQUE INDEX verification_badges_active_type_idx ON verification_badges (company_id, badge_type) WHERE status = 'active'");
        DB::statement("CREATE INDEX verification_badges_expiry_idx ON verification_badges (valid_until) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_badges');
    }
};
