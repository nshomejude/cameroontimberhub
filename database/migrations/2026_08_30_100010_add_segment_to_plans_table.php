<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('segment', 30)->nullable()->after('slug')->index();
        });

        // The existing Free/Professional/Enterprise rows are the domestic
        // supplier tiers — backfill them into the 'sell' segment.
        DB::table('plans')->update(['segment' => 'sell']);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('segment');
        });
    }
};
