<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Additive: extends consents_purpose_check to accept 'company_inquiry_sharing'
 * (App\Enums\ConsentPurpose::CompanyInquirySharing) alongside the existing
 * 'rfq_exporter_sharing' value. Does not touch any existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE consents DROP CONSTRAINT consents_purpose_check');
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_purpose_check CHECK (purpose IN ('rfq_exporter_sharing','company_inquiry_sharing'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE consents DROP CONSTRAINT consents_purpose_check');
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_purpose_check CHECK (purpose IN ('rfq_exporter_sharing'))");
    }
};
