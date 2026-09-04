<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-person control for verification revocation (blueprint §89). Revoking a
 * company's verification is a high-risk action: the first staff member's
 * "revoke" click creates a pending row here rather than mutating anything;
 * a SECOND, DIFFERENT staff member must approve it before any
 * Verification/VerificationBadge state actually changes. See
 * App\Actions\Verification\RequestVerificationRevocation and
 * App\Actions\Verification\ApproveVerificationRevocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_revocation_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('status');
        });

        DB::statement("ALTER TABLE verification_revocation_requests ADD CONSTRAINT verification_revocation_requests_status_check CHECK (status IN ('pending','approved','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_revocation_requests');
    }
};
