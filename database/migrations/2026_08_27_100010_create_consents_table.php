<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A single, reusable, polymorphic consent ledger for any subject that needs
 * revocable, inspectable consent — the RFQ wizard's "share with verified
 * exporters" checkbox today (see
 * docs/superpowers/plans/2026-08-27-persisted-consent.md); the contact/inquiry
 * forms' own consent checkboxes and per-driver/vehicle/policy telematics
 * consent (brief §7.6) as those flows are wired on or built.
 *
 * `purpose` is a closed enum (App\Enums\ConsentPurpose) backed by the CHECK
 * constraint below, following the same convention `documents.verification_status`
 * already uses (see database/migrations/2026_08_28_100010_create_documents_table.php).
 *
 * `scope` is structured JSON, not a free-text tag: §7.6 needs to say *which*
 * driver, vehicle, or policy a grant covers, and the RFQ consumer already
 * needs to say the checkbox covers two things at once (exporter data sharing
 * + email contact) rather than collapsing them into an untyped string.
 *
 * `evidence` mirrors document_access_logs' ip_address/user_agent shape (see
 * database/migrations/2026_06_22_110030_create_document_access_logs_table.php),
 * captured once at grant time as a self-contained JSON snapshot.
 *
 * Revocation (`revoked_at`) is enforced, not decorative — see
 * Consent::scopeActive() and RfqTriageService::route(), which refuses to
 * route an RFQ whose consent has been revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');
            $table->string('purpose', 40);
            $table->jsonb('scope')->nullable();
            $table->timestampTz('granted_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id']);
            $table->index('purpose');
        });

        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_purpose_check CHECK (purpose IN ('rfq_exporter_sharing'))");
        DB::statement('CREATE INDEX consents_active_idx ON consents (subject_type, subject_id) WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
