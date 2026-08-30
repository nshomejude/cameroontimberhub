<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Traceability Event Ledger (implementation blueprint §10): an
 * append-only, hash-chained log of custody/processing events for a
 * TimberLot. Follows the same hash-linking approach as ChainedActivity
 * (app/Models/ChainedActivity.php) — sha256 of the row's own core fields
 * plus the previous row's hash — rather than inventing a new scheme.
 *
 * Chaining scope: PER LOT, not global. Unlike the activity log (a single
 * flat audit trail for the whole app), each lot's ledger is a
 * self-contained chain of custody that should be independently
 * verifiable/exportable (e.g. for a Timber Passport handed to a buyer)
 * without pulling in unrelated lots' events. previous_event_hash is
 * therefore null only for a lot's first event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('timber_lot_id')->constrained('timber_lots')->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at');
            $table->string('location')->nullable();
            $table->decimal('quantity_before', 14, 2)->nullable();
            $table->decimal('quantity_after', 14, 2)->nullable();
            $table->jsonb('documents')->default('{}');
            $table->jsonb('evidence')->default('{}');
            $table->text('notes')->nullable();
            $table->string('previous_event_hash', 64)->nullable();
            $table->string('event_hash', 64);
            $table->timestampsTz();

            $table->index('timber_lot_id');
        });

        DB::statement("ALTER TABLE lot_events ADD CONSTRAINT lot_events_event_type_check CHECK (event_type IN ('source_registered','harvest_recorded','transport_dispatched','arrival_at_processor','processing_started','processing_completed','drying_started','drying_completed','quality_checked','inspection_passed','packaged','container_loaded','customs_submitted','shipped','delivered'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_events');
    }
};
