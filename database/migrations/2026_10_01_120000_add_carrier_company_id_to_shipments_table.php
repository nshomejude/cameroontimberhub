<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carrier visibility (logistics core loop): which company is physically
 * moving a shipment. Set by ShipmentService from the chosen vehicle's/
 * driver's company or an explicit carrier; null means "not assigned yet".
 * Additive + nullable, so every existing shipment row stays valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('carrier_company_id')->nullable()->after('driver_id')
                ->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carrier_company_id');
        });
    }
};
