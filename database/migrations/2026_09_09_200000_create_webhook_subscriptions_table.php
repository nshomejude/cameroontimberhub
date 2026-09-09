<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Architecture plan (docs/superpowers/plans/2026-09-09-api-first-ddd-cqrs-event-driven.md,
     * Task 0.5): a company-owned webhook subscription. `event_types` is a
     * JSON array of dot-namespaced event type strings (see
     * App\Jobs\RelayOutboxEventsJob::EVENT_MAP for the current set, e.g.
     * "order.awarded"). `secret_hash` stores only a hash of the signing
     * secret (mirrors how personal_access_tokens never stores the plaintext
     * token) — the plaintext is shown once on creation, same UX as an API
     * key (see App\Filament\Resources\ApiKeyIssuanceRequests).
     */
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->jsonb('event_types');
            $table->string('secret_hash');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
