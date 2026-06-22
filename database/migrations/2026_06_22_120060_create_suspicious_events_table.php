<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspicious_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 40);
            $table->string('severity', 10)->default('low');
            $table->string('subject_type', 255)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->jsonb('context')->nullable();
            // Append-only event log: created_at only.
            $table->timestampTz('created_at')->nullable();

            $table->index('event_type');
            $table->index('ip_address');
            $table->index(['severity', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE suspicious_events ADD CONSTRAINT suspicious_events_event_type_check CHECK (event_type IN ('honeypot_triggered','rate_limited','rapid_rfq_burst','repeated_failed_login','suspicious_rfq','duplicate_submission'))");
        DB::statement("ALTER TABLE suspicious_events ADD CONSTRAINT suspicious_events_severity_check CHECK (severity IN ('low','medium','high'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('suspicious_events');
    }
};
