<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per delivery attempt cycle for a given outbox event against a
     * given subscription (architecture plan, Task 0.5). `attempt` counts up
     * as App\Jobs\DeliverWebhookJob retries (1, 2, 3); `delivered_at` is set
     * on the first 2xx response, `failed_permanently_at` after the final
     * (3rd) failed attempt — a row can only end in exactly one of those two
     * terminal states, never both.
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->string('event_type');
            $table->jsonb('payload');
            $table->smallInteger('response_code')->nullable();
            $table->smallInteger('attempt')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_permanently_at')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
