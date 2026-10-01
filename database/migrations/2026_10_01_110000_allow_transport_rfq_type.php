<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * App\Enums\RfqType gained `transport` (the public RFQ wizard already offers
 * it) but rfqs_type_check still only allowed export/domestic_manufacturing,
 * so every transport RFQ failed at insert. Widen the check to match the enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE rfqs DROP CONSTRAINT IF EXISTS rfqs_type_check');
        DB::statement("ALTER TABLE rfqs ADD CONSTRAINT rfqs_type_check CHECK (type IN ('export','domestic_manufacturing','transport'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE rfqs DROP CONSTRAINT IF EXISTS rfqs_type_check');
        DB::statement("ALTER TABLE rfqs ADD CONSTRAINT rfqs_type_check CHECK (type IN ('export','domestic_manufacturing'))");
    }
};
