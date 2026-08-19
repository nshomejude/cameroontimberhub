<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real, supplier-entered logistics and settlement facts.
 *
 * There is no carrier integration and no payment integration on this platform.
 * Every column below is therefore nullable and stays null until a human on the
 * supplier side types the value in; the cards render only what is populated, so
 * an absent carrier shows nothing rather than "To be assigned".
 *
 * `payment_instructions` lives on the COMPANY (a supplier's bank details do not
 * change per order) while `payment_due_at` and `payment_reference` live on the
 * ORDER. None of these move money — they are text the supplier publishes so the
 * buyer can settle off-platform, exactly as `orders.payment_status` already
 * documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // ---- shipment (supplier-entered, no carrier API behind any of it)
            $table->string('carrier', 120)->nullable()->after('shipping_port');
            $table->string('tracking_number', 120)->nullable()->after('carrier');
            $table->string('tracking_url', 500)->nullable()->after('tracking_number');
            $table->string('shipping_method', 120)->nullable()->after('tracking_url');
            $table->string('vessel_name', 120)->nullable()->after('shipping_method');
            $table->string('voyage_number', 60)->nullable()->after('vessel_name');
            $table->string('container_number', 60)->nullable()->after('voyage_number');
            $table->string('port_of_loading', 120)->nullable()->after('container_number');
            $table->string('port_of_discharge', 120)->nullable()->after('port_of_loading');
            $table->date('etd')->nullable()->after('port_of_discharge');
            $table->date('eta')->nullable()->after('etd');

            // ---- delivery facts recorded when the supplier marks it delivered
            $table->string('delivered_to_name', 160)->nullable()->after('delivered_at');
            $table->string('delivery_location', 200)->nullable()->after('delivered_to_name');

            // ---- settlement (still nothing automatic; see OrderPaymentStatus)
            $table->date('payment_due_at')->nullable()->after('payment_recorded_at');
            $table->string('payment_reference', 120)->nullable()->after('payment_due_at');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->text('payment_instructions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'carrier', 'tracking_number', 'tracking_url', 'shipping_method',
                'vessel_name', 'voyage_number', 'container_number',
                'port_of_loading', 'port_of_discharge', 'etd', 'eta',
                'delivered_to_name', 'delivery_location',
                'payment_due_at', 'payment_reference',
            ]);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('payment_instructions');
        });
    }
};
