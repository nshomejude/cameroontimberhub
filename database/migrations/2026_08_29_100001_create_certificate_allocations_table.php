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
 * RESOLVED (0.8c, part 1): an allocation row still references one specific
 * certificate row (one version), but CertificateAllocationService::remaining()
 * sums allocations across every row sharing the same certificate_number --
 * the whole version chain -- not just the current row's certificate_id. A
 * re-issued version therefore inherits its predecessor's already-claimed
 * quantity instead of starting clean, so the same certified quantity cannot
 * be double-claimed across a version boundary created by
 * CertificateService::createVersion(). No schema change was needed.
 *
 * KNOWN LIMITATION still open, stated honestly rather than silently: the
 * signing/verification half of 0.8c (moving certificate signing to a real
 * KMS/HSM provider) is separate follow-up work, tracked in docs/GAP_PLAN.md.
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
