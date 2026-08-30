<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §16 Regulatory Source Registry: "No compliance requirement
 * should exist in the production rules engine without a source and review
 * date." This table is that source record; compliance_rules enforces the
 * relationship via a restrictOnDelete FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulatory_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('authority', 255);
            $table->string('instrument_name', 255);
            $table->string('jurisdiction', 120);
            $table->string('reference_number', 100)->nullable();
            $table->date('effective_date')->nullable();
            $table->string('official_url', 500)->nullable();
            $table->date('last_checked_at')->nullable();
            $table->date('next_review_date')->nullable();
            $table->text('summary')->nullable();
            $table->text('internal_interpretation')->nullable();
            $table->string('legal_review_status', 30)->default('pending');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE regulatory_sources ADD CONSTRAINT regulatory_sources_legal_review_status_check CHECK (legal_review_status IN ('pending','reviewed','needs_update'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('regulatory_sources');
    }
};
