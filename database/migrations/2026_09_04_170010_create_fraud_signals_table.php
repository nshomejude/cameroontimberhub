<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §25 initial anti-fraud detection: a reviewable flag raised by
 * FraudDetectionService. Detection-and-alerting only — this table is never
 * read by anything that blocks or suspends an account/listing; it exists
 * purely for a human admin to review via the FraudSignals Filament resource.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_signals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('signal_type', 40);
            $table->string('severity', 20)->default('low');
            $table->jsonb('details')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id']);
            $table->index('signal_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_signals');
    }
};
