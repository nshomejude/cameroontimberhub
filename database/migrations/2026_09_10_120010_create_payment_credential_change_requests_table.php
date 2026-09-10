<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two-person + step-up-2FA gated payment credential changes — mirrors
        // ai_api_key_change_requests. requested_by proposes new credentials
        // (stored encrypted here, never logged in plaintext) and generates an
        // invite token (hashed here, shown once in plaintext to relay
        // out-of-band). approved_by must be a different admin who supplies
        // that token AND has a recent 2FA confirmation.
        Schema::create('payment_credential_change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('environment')->default('sandbox');
            $table->text('proposed_credentials'); // encrypted:array JSON blob
            $table->string('invite_token_hash');
            $table->timestamp('expires_at');
            $table->string('status')->default('pending'); // pending | approved | rejected | expired
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_credential_change_requests');
    }
};
