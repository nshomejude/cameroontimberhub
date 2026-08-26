<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            // Buyer-supplied headline for the request (step 1 of the wizard) and
            // the optional project it belongs to. Nullable because RFQs created
            // before the wizard existed have neither.
            $table->string('title', 160)->nullable()->after('reference_code');
            $table->string('project_name', 160)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn(['title', 'project_name']);
        });
    }
};
