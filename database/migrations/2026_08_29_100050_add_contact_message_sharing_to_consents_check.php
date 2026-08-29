<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE consents DROP CONSTRAINT consents_purpose_check');
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_purpose_check CHECK (purpose IN ('rfq_exporter_sharing','company_inquiry_sharing','contact_message_sharing'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE consents DROP CONSTRAINT consents_purpose_check');
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_purpose_check CHECK (purpose IN ('rfq_exporter_sharing','company_inquiry_sharing'))");
    }
};
