<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->nullOnDelete();
            $table->foreignId('rfq_company_id')->nullable()->constrained('rfq_company')->nullOnDelete();
            $table->foreignId('company_inquiry_id')->nullable()->constrained('company_inquiries')->nullOnDelete();
            $table->string('source', 20)->default('rfq');
            $table->string('status', 20)->default('new');
            $table->string('buyer_name', 255)->nullable();
            $table->string('buyer_email', 255)->nullable();
            $table->char('buyer_country_code', 2)->nullable();
            $table->decimal('value_amount', 14, 2)->nullable();
            $table->char('value_currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('last_activity_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['company_id', 'status']);
            $table->index('last_activity_at');
            $table->index('rfq_id');
        });

        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_status_check CHECK (status IN ('new','contacted','won','lost','dormant'))");
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_source_check CHECK (source IN ('rfq','inquiry','manual'))");
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_currency_check CHECK (value_currency IS NULL OR value_currency IN ('XAF','USD','EUR','GBP','CNY'))");
        DB::statement('CREATE UNIQUE INDEX leads_rfq_company_idx ON leads (rfq_company_id) WHERE rfq_company_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
