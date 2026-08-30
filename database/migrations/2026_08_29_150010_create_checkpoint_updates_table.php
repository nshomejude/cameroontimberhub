<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual checkpoint tracking (gap-plan 1.5.11). Generic and polymorphic over
 * `trackable_type`/`trackable_id` — mirroring `consents.subject_type`/
 * `subject_id` (see 2026_08_27_100010_create_consents_table.php) — because
 * the real consumer (`Shipment`, gap-plan 1.5.10) is being built concurrently
 * and may not exist yet. Shipment becomes just one future trackable via
 * `HasCheckpointUpdates`; wiring it up is an explicit follow-up (see
 * docs/superpowers/plans/2026-08-29-checkpoint-tracking.md, Scope Decision).
 *
 * `tracking_token` is a separate high-entropy public identifier — never the
 * `id` — following the exact convention of `certificates.verification_token`
 * and `receipts.verification_token`. Unlike those, it is deliberately NOT
 * a row-level unique key: one token identifies a trackable's whole
 * checkpoint *history*, so every checkpoint recorded for the same
 * trackable shares the same token, and a public `/track/{token}` lookup
 * returns all of them together (see App\Services\CheckpointTracker).
 * Uniqueness is therefore enforced per-trackable, not per-row: application
 * code generates the token once, the first time a trackable is checkpointed
 * (checking `CheckpointUpdate::forToken()` for a collision, the same
 * do-while-until-unique discipline as OrderReferenceGenerator), and every
 * later checkpoint for that trackable reuses it. A plain (non-unique) index
 * on the column keeps `/track/{token}` lookups fast without preventing the
 * intentional duplication across rows.
 *
 * `latitude`/`longitude` are nullable: "no telematics yet" means there is no
 * device feed, only a human optionally attaching their phone's GPS position
 * at the moment they record a checkpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkpoint_updates', function (Blueprint $table) {
            $table->id();
            $table->string('trackable_type', 120);
            $table->unsignedBigInteger('trackable_id');
            $table->string('tracking_token', 64);
            $table->string('status', 40);
            $table->string('location')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('photo_path', 512)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trackable_type', 'trackable_id']);
            $table->index('tracking_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkpoint_updates');
    }
};
