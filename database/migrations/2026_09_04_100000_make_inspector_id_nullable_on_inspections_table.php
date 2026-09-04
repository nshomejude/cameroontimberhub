<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company self-service inspection requests (blueprint §26/§27 follow-up):
 * a company can now request an inspection before staff/matching assigns an
 * inspector. When no eligible inspector matches at request time, the
 * inspection row is still created so staff can see and manually assign it
 * later -- so inspector_id must be nullable rather than a hard-required FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropForeign(['inspector_id']);
        });

        DB::statement('ALTER TABLE inspections ALTER COLUMN inspector_id DROP NOT NULL');

        Schema::table('inspections', function (Blueprint $table) {
            $table->foreign('inspector_id')->references('id')->on('inspectors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropForeign(['inspector_id']);
        });

        DB::statement('DELETE FROM inspections WHERE inspector_id IS NULL');
        DB::statement('ALTER TABLE inspections ALTER COLUMN inspector_id SET NOT NULL');

        Schema::table('inspections', function (Blueprint $table) {
            $table->foreign('inspector_id')->references('id')->on('inspectors')->restrictOnDelete();
        });
    }
};
