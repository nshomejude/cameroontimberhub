<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chain-of-custody / quantity binding (docs/CERTIFICATE_SPEC.md Ring 2,
 * Layer 14): how much of a specific certificate ROW's certified_quantity
 * has been claimed, and by which consumer.
 *
 * The consumer is polymorphic on purpose. An Order is the consumer this is
 * built for, and a future Shipment/Lot will be another, with no migration
 * needed for either. (Tests exercise it against a Quote, because Order rows
 * are deliberately only creatable through OrderService::createFromQuote()
 * and therefore have no factory -- see App\Models\Order's docblock.)
 *
 * KNOWN LIMITATION, stated honestly rather than silently: an allocation
 * references one certificate row (one version). If a certificate is
 * versioned (CertificateService::createVersion), existing allocations stay
 * attached to the SUPERSEDED row and are not automatically carried forward
 * to the new version. Carrying allocations across versions is real follow-up
 * work, tracked in docs/GAP_PLAN.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_allocations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('certificate_id')->constrained('certificates')->cascadeOnDelete();
            $table->string('consumer_type', 120);
            $table->unsignedBigInteger('consumer_id');
            $table->decimal('quantity', 14, 3);
            $table->timestampsTz();

            $table->index('certificate_id');
            $table->index(['consumer_type', 'consumer_id']);
        });

        DB::statement('ALTER TABLE certificate_allocations ADD CONSTRAINT certificate_allocations_quantity_positive CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_allocations');
    }
};
