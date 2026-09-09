<?php

namespace Database\Seeders;

use App\Models\Inspection;
use App\Models\Inspector;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds exactly one Shipment and one Inspection so the offline PWA field-
 * capture pages (blueprint §45-46 — logistics checkpoint recording,
 * inspector report submission) are actually walkable in the live demo
 * instead of being empty/unreachable for lack of any real row to point at.
 *
 * Deliberately minimal and idempotent (firstOrCreate throughout, safe to
 * re-run): this seeds the two rows the field-capture flows need, nothing
 * more. The Inspector profile is attached to the existing demo admin
 * account (config('demo.personas.admin')) rather than inventing a fourth
 * demo persona — DemoLoginSeeder's admin button already reaches an account
 * that can therefore also open the inspector report page directly at
 * /inspector/inspections/{inspection}/report.
 *
 * The Shipment's checkpoint history is deliberately left empty — an
 * inspector/driver recording the FIRST live checkpoint through the actual
 * offline-capable form is the more honest and more useful demo than
 * pre-filling fake history.
 *
 *     php artisan db:seed --class=DemoFieldCaptureSeeder
 */
class DemoFieldCaptureSeeder extends Seeder
{
    public function run(): void
    {
        $order = Order::query()->first();

        if ($order === null) {
            $this->command?->warn('DemoFieldCaptureSeeder: no orders on file — run the order seeders first.');

            return;
        }

        $shipment = Shipment::query()->firstOrCreate(
            ['order_id' => $order->getKey()],
            [
                'waybill_number' => 'WB-DEMO-'.str_pad((string) $order->getKey(), 4, '0', STR_PAD_LEFT),
                'origin' => $order->supplier_name ?? 'Douala, Cameroon',
                'destination' => $order->shipping_port ?: 'Antwerp, Belgium',
            ],
        );

        $adminEmail = config('demo.personas.admin.email');
        $inspectorUser = $adminEmail ? User::query()->where('email', $adminEmail)->first() : null;

        if ($inspectorUser === null) {
            $this->command?->warn('DemoFieldCaptureSeeder: demo admin account not found — run DemoLoginSeeder first.');
            $this->command?->info("Shipment ready: {$shipment->waybill_number}");

            return;
        }

        $inspector = Inspector::query()->firstOrCreate(
            ['user_id' => $inspectorUser->getKey()],
            [
                'organisation_name' => 'Cameroon Timber Hub (demo)',
                'identity_verified_at' => now(),
                'agreement_accepted_at' => now(),
                'coverage_regions' => ['CM'],
                'inspection_categories' => ['pre_shipment', 'quantity', 'quality_grade'],
                'years_experience' => 8,
                'status' => 'active',
            ],
        );

        $inspection = Inspection::query()->firstOrCreate(
            ['inspector_id' => $inspector->getKey(), 'order_id' => $order->getKey()],
            [
                'inspection_type' => 'pre_shipment',
                'scheduled_for' => now()->addDay()->toDateString(),
                'location' => $shipment->origin,
            ],
        );

        $this->command?->info("Demo field-capture data ready: shipment {$shipment->waybill_number}, inspection #{$inspection->id} (inspector: {$inspectorUser->email}).");
    }
}
