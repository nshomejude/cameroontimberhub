<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `Idempotency-Key` replay store for the agent ingestion endpoints. One
     * row per (token, key); the stored response is replayed verbatim on a
     * retry with the same payload. Pruned after 7 days by the scheduled
     * `agent:prune-idempotency-keys` command.
     */
    public function up(): void
    {
        Schema::create('agent_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('token_id');
            $table->string('key', 255);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('status')->nullable();
            // Raw JSON text (not jsonb) so a replay is byte-identical.
            $table->longText('body')->nullable();
            $table->timestampsTz();

            $table->unique(['token_id', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_idempotency_keys');
    }
};
