<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two-person + step-up-2FA gated API key issuance (mirrors
        // ai_api_key_change_requests / App\Actions\Ai\*). requested_by
        // proposes a named key with a set of Sanctum abilities for a
        // company; this row holds the PROPOSAL only — no Sanctum token
        // exists yet. invite_token_hash is a one-time token shown in
        // plaintext to the requester once, to relay out-of-band to a
        // different admin. approved_by must be that different admin,
        // supplying the token AND a recent 2FA confirmation, at which
        // point App\Actions\ApiKeys\ApproveApiKeyIssuance actually creates
        // the Sanctum personal access token.
        Schema::create('api_key_issuance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('requested_name');
            $table->jsonb('requested_abilities');
            $table->string('invite_token_hash');
            $table->timestamp('expires_at');
            $table->string('status')->default('pending');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_issuance_requests');
    }
};
