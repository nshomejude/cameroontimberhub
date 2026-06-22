<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_inquiries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('phone', 32)->nullable();
            $table->text('message');
            $table->string('status', 20)->default('new');
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'status']);
            $table->index('email');
        });

        DB::statement("ALTER TABLE company_inquiries ADD CONSTRAINT company_inquiries_status_check CHECK (status IN ('new','in_review','approved','rejected','spam','closed'))");
        DB::statement("CREATE INDEX company_inquiries_new_idx ON company_inquiries (status) WHERE status = 'new'");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_inquiries');
    }
};
