<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional Outbox (architecture plan §"Event-Driven Backbone", Task
 * 0.2): a row here MUST be inserted inside the same DB transaction as the
 * state change that produced it (see App\Support\Events\RecordsOutboxEvents)
 * so state and event either both commit or both roll back — no dual-write
 * gap between "the order was awarded" and "anyone found out about it".
 *
 * `App\Jobs\RelayOutboxEventsJob` polls unpublished rows (published_at IS
 * NULL) and dispatches the matching real Laravel event, then stamps
 * published_at. Only created_at is tracked (no updated_at) — a row is
 * written once and only ever has published_at/attempts mutated afterward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();

            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('event_type')->index();
            $table->jsonb('payload');

            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable()->index();
            $table->smallInteger('attempts')->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['published_at', 'attempts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
