<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transport booking turned into a trackable shipment with a digital
 * waybill (gap-plan 1.5.10). Belongs to the Order that is the booking.
 *
 * `vehicle_id`/`driver_id` are deliberately plain nullable integers, NOT
 * foreign keys: gap-plan 1.5.12 (fleet/driver registry) is being built
 * concurrently and its `vehicles`/`drivers` tables do not exist yet. A
 * Shipment must be creatable and fully functional with both null — once
 * 1.5.12 lands, a follow-up migration adds the real FK constraints.
 *
 * No status/location/checkpoint columns here on purpose: gap-plan 1.5.11
 * (manual checkpoint tracking) is a separate, generic, polymorphic
 * `CheckpointUpdate` model (owner_type/owner_id) that will attach to a
 * Shipment as one of its trackable subjects later. This table's only job
 * is to exist as a bookable, waybill-bearing subject for that to point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Unguessable public token used in the waybill URL/QR — never the
            // auto-increment id, so the waybill URL can't be enumerated.
            $table->string('waybill_number', 40)->unique();

            // Fleet linkage (gap-plan 1.5.12), intentionally not yet a real FK — see class doc above.
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();

            $table->string('origin', 200)->nullable();
            $table->string('destination', 200)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
