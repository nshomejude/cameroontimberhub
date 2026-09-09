<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lightweight per-API-key usage tracking (architecture plan §4 — "API as
     * a product": you cannot sensibly price or rate-limit something you
     * can't measure). Deliberately daily-aggregated, NOT a per-request log —
     * a per-request table would grow unbounded for no benefit here. One row
     * per (token, day), upserted/incremented on every `/api/v1` request.
     */
    public function up(): void
    {
        Schema::create('api_key_usage_dailies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamps();

            $table->unique(['personal_access_token_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_usage_dailies');
    }
};
