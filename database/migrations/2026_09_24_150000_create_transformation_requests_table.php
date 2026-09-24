<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transformation Requests — the request/accept/quote/job pipeline for the
 * Transformation Network directory (Api\V1\TransformationNetworkController,
 * read-only). A buyer/supplier/retailer company asks a verified
 * processor/manufacturer company to perform a transformation service
 * (sawing/drying/planing/...); on completion a real LotTransformation ledger
 * row is created — see App\Services\TransformationRequestService.
 *
 * `timeline` is a jsonb activity trail appended to on every status
 * transition (`{status, label, at}` entries). A dedicated child table
 * (mirroring LotEvent's hash-chained ledger) would be over-engineering for
 * what is a simple, append-only, non-evidentiary status trail — LotEvent
 * exists because traceability events need tamper-evidence; this does not.
 * A jsonb column on the row itself is the lighter precedent already used
 * elsewhere in this codebase for structured-but-simple per-row data (e.g.
 * `species.local_names`, `timber_lots.origin_boundary`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transformation_request_reference_sequences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('last_value')->default(0);
        });

        Schema::create('transformation_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 40)->unique();

            $table->foreignId('requester_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('provider_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('service', 40);
            $table->foreignId('species_id')->nullable()->constrained('species')->nullOnDelete();
            $table->decimal('volume_m3', 12, 3);
            $table->text('input_description')->nullable();
            $table->text('target_spec')->nullable();
            $table->date('deadline')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 20)->default('pending');

            $table->decimal('quote_amount', 14, 2)->nullable();
            $table->string('quote_currency', 3)->nullable();
            $table->unsignedInteger('quote_lead_time_days')->nullable();
            $table->text('quote_notes')->nullable();

            $table->text('decline_reason')->nullable();

            $table->foreignId('lot_transformation_id')->nullable()->constrained('lot_transformations')->nullOnDelete();

            $table->jsonb('timeline')->default('[]');

            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->timestampsTz();

            $table->index(['requester_company_id', 'status']);
            $table->index(['provider_company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transformation_requests');
        Schema::dropIfExists('transformation_request_reference_sequences');
    }
};
