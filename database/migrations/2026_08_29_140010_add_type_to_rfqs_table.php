<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `rfqs.type`, distinguishing the original export wizard flow from the
 * new domestic manufacturing / local procurement / project RFQ flow (gap-plan
 * 1.5.5). Additive only: defaults every existing row to 'export', which is
 * exactly the behavior every one of them already had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->string('type', 30)->default('export')->after('visibility');
        });

        DB::statement("ALTER TABLE rfqs ADD CONSTRAINT rfqs_type_check CHECK (type IN ('export','domestic_manufacturing'))");
    }

    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            DB::statement('ALTER TABLE rfqs DROP CONSTRAINT IF EXISTS rfqs_type_check');
            $table->dropColumn('type');
        });
    }
};
