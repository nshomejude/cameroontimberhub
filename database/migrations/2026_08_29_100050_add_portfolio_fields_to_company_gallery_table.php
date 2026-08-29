<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_gallery', function (Blueprint $table) {
            $table->text('description')->nullable()->after('caption');
            $table->string('materials_used', 255)->nullable()->after('description');
            $table->date('completed_on')->nullable()->after('materials_used');
            $table->boolean('is_portfolio')->default(false)->after('completed_on');
        });
    }

    public function down(): void
    {
        Schema::table('company_gallery', function (Blueprint $table) {
            $table->dropColumn(['description', 'materials_used', 'completed_on', 'is_portfolio']);
        });
    }
};
