<?php

use App\Enums\SupplierType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier-directory metrics: the commercial role a company plays plus the two
 * performance figures the approved directory mockup surfaces on every card.
 * All three are nullable — a card hides a stat cleanly when it has no value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('supplier_type', 32)->nullable()->after('status');
            $table->smallInteger('response_rate_percent')->nullable()->after('profile_completion');
            $table->smallInteger('years_experience')->nullable()->after('response_rate_percent');

            $table->index('supplier_type');
        });

        $list = collect(SupplierType::values())->map(fn (string $v): string => "'".$v."'")->implode(',');

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_supplier_type_check CHECK (supplier_type IS NULL OR supplier_type IN ({$list}))");
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_response_rate_check CHECK (response_rate_percent IS NULL OR (response_rate_percent BETWEEN 0 AND 100))');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_years_experience_check CHECK (years_experience IS NULL OR (years_experience BETWEEN 0 AND 200))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_supplier_type_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_response_rate_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_years_experience_check');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['supplier_type']);
            $table->dropColumn(['supplier_type', 'response_rate_percent', 'years_experience']);
        });
    }
};
