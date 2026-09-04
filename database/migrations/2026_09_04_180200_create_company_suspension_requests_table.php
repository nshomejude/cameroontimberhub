<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-person control for company suspension (blueprint §88-89), mirroring
 * verification_revocation_requests. Suspending a company is a high-risk
 * action: the first staff member's "suspend" click creates a pending row
 * here rather than mutating anything; a SECOND, DIFFERENT staff member
 * must approve it before CompanyStatusService actually transitions the
 * company to Suspended. See App\Actions\Company\RequestCompanySuspension
 * and App\Actions\Company\ApproveCompanySuspension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_suspension_requests', function (Blueprint $table) {
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

        DB::statement("ALTER TABLE company_suspension_requests ADD CONSTRAINT company_suspension_requests_status_check CHECK (status IN ('pending','approved','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_suspension_requests');
    }
};
