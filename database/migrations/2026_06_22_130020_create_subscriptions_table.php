<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestampTz('starts_at')->useCurrent();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('active','expired','cancelled'))");
        DB::statement("CREATE UNIQUE INDEX subscriptions_active_idx ON subscriptions (company_id) WHERE status = 'active'");
        DB::statement("CREATE INDEX subscriptions_expiry_idx ON subscriptions (ends_at) WHERE status = 'active'");

        // The deferred companies.plan_id FK (column created in Phase B).
        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
        });

        Schema::dropIfExists('subscriptions');
    }
};
