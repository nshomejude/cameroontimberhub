<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carrier booking acceptance (App\Enums\ShipmentCarrierStatus). Additive +
 * nullable: null = own fleet / no carrier. Existing shipments with a
 * third-party carrier were directly assigned, so they are backfilled to
 * `assigned` (no behaviour change for them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('carrier_status', 20)->nullable()->after('carrier_company_id');
            $table->string('carrier_decline_reason', 500)->nullable()->after('carrier_status');
            $table->timestamp('carrier_responded_at')->nullable()->after('carrier_decline_reason');
        });

        DB::table('shipments')
            ->whereNotNull('carrier_company_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders')
                ->whereColumn('orders.id', 'shipments.order_id')
                ->whereColumn('orders.company_id', 'shipments.carrier_company_id'))
            ->update(['carrier_status' => 'assigned']);
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['carrier_status', 'carrier_decline_reason', 'carrier_responded_at']);
        });
    }
};
