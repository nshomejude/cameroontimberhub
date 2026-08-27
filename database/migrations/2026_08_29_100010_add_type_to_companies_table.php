<?php

use App\Enums\OrganisationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the brief's 10-value Organisation.type (CTH_Claude_Code_Build_Brief.md
 * §10) as `companies.type`, ADDITIVELY, alongside the existing 5-value
 * `companies.supplier_type` column (see
 * 2026_06_26_100030_add_supplier_metrics_to_companies_table.php).
 *
 * Deliberately does NOT drop, rename, or backfill supplier_type here. Two of
 * its five values (exporter, trader) have no clean OrganisationType
 * equivalent — see the "Scope decision" section of
 * docs/superpowers/plans/2026-08-27-organisation-type-migration.md for the
 * three mapping options awaiting a human decision. Only `manufacturer` and
 * `logistics_provider` are backfilled automatically, by the
 * companies:backfill-organisation-type command in Task 3 of that plan — this
 * migration only adds the column and its constraint; it does not populate
 * any row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('type', 32)->nullable()->after('supplier_type');
            $table->index('type');
        });

        $list = collect(OrganisationType::values())->map(fn (string $v): string => "'".$v."'")->implode(',');

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_organisation_type_check CHECK (type IS NULL OR type IN ({$list}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_organisation_type_check');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
