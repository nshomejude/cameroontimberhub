<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `processor` (blueprint mobile screens 030/031) to the allowed
 * `companies.supplier_type` values by dropping and recreating the CHECK
 * constraint originally created in
 * 2026_06_26_100030_add_supplier_metrics_to_companies_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_supplier_type_check');

        $list = implode(',', array_map(fn ($c) => "'{$c->value}'", \App\Enums\SupplierType::cases()));

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_supplier_type_check CHECK (supplier_type IS NULL OR supplier_type IN ({$list}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_supplier_type_check');

        // The pre-Processor case list, exactly as it existed when this
        // migration was written (not a live enum read, since down() must
        // still work correctly after the Processor case is removed from
        // the enum in a future rollback ordering).
        $list = "'manufacturer','exporter','trader','service_provider','logistics_provider'";

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_supplier_type_check CHECK (supplier_type IS NULL OR supplier_type IN ({$list}))");
    }
};
