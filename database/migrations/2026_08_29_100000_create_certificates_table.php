<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rings 1+2 (Layers 1-14) canonical certificate record (gap-plan 0.8,
 * docs/CERTIFICATE_SPEC.md). Each ROW is one immutable version -- see the
 * plan's "Versioning shape" scope-decision note for why this differs from
 * the Verification/VerificationCheckpoint parent+event-log split.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Identity (Layer 1) -- shared across versions of the same
            // certificate, so it is deliberately NOT unique on its own.
            $table->string('certificate_number', 40);
            // Verification secret (Layer 2) -- unique PER ROW/VERSION, never
            // derived from certificate_number. A superseded version's token
            // still resolves, but the verification payload reports it as not
            // the current version (see CertificateVerifier).
            $table->string('verification_token', 64)->unique();

            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');

            // Canonical record (Layer 3) + hash (Layer 4).
            $table->json('data');
            $table->char('data_hash', 64)->nullable();

            // Versioning (Layer 11).
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('previous_version_id')->nullable();
            $table->text('version_reason')->nullable();
            $table->foreignId('version_actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('draft');

            // Signature (Layers 5-6).
            $table->string('key_id', 60)->nullable();
            $table->string('algorithm', 30)->nullable();
            $table->text('signature')->nullable();
            $table->timestampTz('signed_at')->nullable();

            // Evidence manifest (Layer 12).
            $table->char('evidence_manifest_hash', 64)->nullable();

            // Geospatial (Layer 13).
            $table->json('geospatial_data')->nullable();
            $table->char('geospatial_hash', 64)->nullable();

            // Quantity binding (Layer 14) -- the certified total this
            // certificate's allocations are checked against.
            $table->decimal('certified_quantity', 14, 3)->nullable();
            $table->string('quantity_unit', 20)->nullable();

            // Trusted timestamps (Layer 7).
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('issued_at')->nullable();

            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id']);
            $table->index('certificate_number');
            $table->unique(['certificate_number', 'version']);
            $table->foreign('previous_version_id')->references('id')->on('certificates')->nullOnDelete();
        });

        DB::statement("ALTER TABLE certificates ADD CONSTRAINT certificates_status_check CHECK (status IN ('draft','submitted','under_review','verified','issued','active','suspended','revoked','superseded','replaced','withdrawn'))");

        // At most one row per certificate_number may be the "live" (non
        // superseded/replaced) version at a time -- the versioning invariant
        // CertificateService::createVersion() enforces at the application
        // layer, backed here at the database layer. This is why createVersion()
        // supersedes the current row BEFORE inserting its successor: Postgres
        // evaluates this index per statement, not at commit.
        DB::statement("CREATE UNIQUE INDEX certificates_one_live_version_idx ON certificates (certificate_number) WHERE status NOT IN ('superseded','replaced')");
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
