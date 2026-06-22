<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_company', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('status', 20)->default('sent');
            $table->foreignId('routed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('routed_at')->nullable();
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->text('response_notes')->nullable();
            $table->timestampsTz();

            $table->unique(['rfq_id', 'company_id']);
            $table->index(['company_id', 'status']);
            $table->index('rfq_id');
        });

        DB::statement("ALTER TABLE rfq_company ADD CONSTRAINT rfq_company_status_check CHECK (status IN ('sent','viewed','responded','declined'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_company');
    }
};
