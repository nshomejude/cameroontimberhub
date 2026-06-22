<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('reference_code', 40)->unique();
            $table->string('buyer_name', 255);
            $table->string('buyer_company', 255)->nullable();
            $table->string('buyer_email', 255);
            $table->string('buyer_phone', 32)->nullable();
            $table->char('buyer_country_code', 2)->nullable();
            $table->char('destination_country_code', 2)->nullable();
            $table->string('incoterm', 10)->nullable();
            $table->decimal('target_amount', 14, 2)->nullable();
            $table->char('target_currency', 3)->nullable();
            $table->date('deadline')->nullable();
            $table->string('shipping_port', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('new');
            $table->string('visibility', 20)->default('public');
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('source', 40)->nullable();
            $table->integer('spam_score')->default(0);
            $table->boolean('is_spam')->default(false);
            $table->jsonb('attachments')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->index('buyer_email');
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE rfqs ADD CONSTRAINT rfqs_status_check CHECK (status IN ('new','in_review','approved','rejected','spam','closed'))");
        DB::statement("ALTER TABLE rfqs ADD CONSTRAINT rfqs_visibility_check CHECK (visibility IN ('public','private','admin_assisted'))");
        DB::statement("ALTER TABLE rfqs ADD CONSTRAINT rfqs_currency_check CHECK (target_currency IS NULL OR target_currency IN ('XAF','USD','EUR','GBP','CNY'))");
        DB::statement("ALTER TABLE rfqs ADD CONSTRAINT rfqs_incoterm_check CHECK (incoterm IS NULL OR incoterm IN ('FOB','CIF','CFR','EXW','DAP','other'))");
        DB::statement("CREATE INDEX rfqs_new_queue_idx ON rfqs (status) WHERE status = 'new' AND deleted_at IS NULL");
        DB::statement('CREATE INDEX rfqs_spam_idx ON rfqs (is_spam) WHERE is_spam = true');
        DB::statement('CREATE INDEX rfqs_unverified_idx ON rfqs (email_verified_at) WHERE email_verified_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('rfqs');
    }
};
