<?php

use App\Domain\Trade\Commands\RecordOrderShipmentCommand;
use App\Enums\OrganisationType;
use App\Enums\TrackingCheckpointStatus;
use App\Filament\Exporter\Resources\Orders\Pages\ListOrders;
use App\Filament\Exporter\Resources\Shipments\Pages\ListShipments;
use App\Filament\Exporter\Resources\Shipments\Pages\ViewShipment;
use App\Models\CheckpointUpdate;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\ShipmentAssignedNotification;
use App\Notifications\ShipmentDeliveredNotification;
use App\Notifications\ShipmentUpdateNotification;
use App\Services\OrderService;
use App\Services\QuoteService;
use App\Services\ShipmentService;
use App\Support\Bus\CommandBus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/**
 * A real awarded order (via the quote-accept path) plus a logged-in-able
 * supplier member and the buyer.
 *
 * @return array{order: Order, supplier: User, company: Company, buyer: User}
 */
function shipmentApiFixture(): array
{
    $buyer = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();
    $supplier = User::factory()->create();
    $company->users()->attach($supplier, ['role' => 'owner', 'is_primary' => true]);

    $rfq = Rfq::factory()->approved()->create(['user_id' => $buyer->getKey()]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3']);
    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'status' => 'sent', 'routed_at' => now(),
    ]);
    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'rfq_company_id' => $routing->getKey(),
    ]);
    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(), 'description' => 'Sawn timber', 'quantity' => 100,
        'unit_price' => 185.00, 'line_total' => Quote::lineTotal(100, 185.00),
    ]);
    $quote->load('items')->recalculateTotals()->save();
    app(QuoteService::class)->accept($quote);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    return compact('order', 'supplier', 'company', 'buyer');
}

/** @return array{0: Company, 1: User} */
function shipmentApiCarrier(): array
{
    $carrier = Company::factory()->create(['type' => OrganisationType::Logistics]);
    $user = User::factory()->create();
    $carrier->users()->attach($user, ['role' => 'member', 'is_primary' => true]);

    return [$carrier, $user];
}

/* ------------------------------------------------------------ creation */

it('creates a shipment with a partner carrier vehicle + driver and the buyer then sees it', function () {
    ['order' => $order, 'supplier' => $supplier, 'buyer' => $buyer] = shipmentApiFixture();
    [$carrier] = shipmentApiCarrier();
    $vehicle = Vehicle::factory()->create(['company_id' => $carrier->id, 'is_active' => true]);
    $driver = Driver::factory()->create(['company_id' => $carrier->id, 'is_active' => true]);

    $response = $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/shipments", [
            'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id,
            'origin' => 'Douala', 'destination' => 'Le Havre',
        ])
        ->assertCreated()
        ->assertJsonPath('data.carrier_company.id', $carrier->id)
        ->assertJsonPath('data.vehicle.id', $vehicle->id)
        ->assertJsonPath('data.order_reference', $order->reference_code);

    $waybill = $response->json('data.waybill_number');
    expect($waybill)->toStartWith('WB-')
        ->and($response->json('data.waybill_url'))->toContain("/shipments/{$waybill}/waybill");

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/orders/{$order->reference_code}/shipments")
        ->assertOk()
        ->assertJsonPath('data.0.waybill_number', $waybill);
});

it('rejects a vehicle from a non-logistics third-party company', function () {
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();
    $stranger = Company::factory()->create();
    $vehicle = Vehicle::factory()->create(['company_id' => $stranger->id, 'is_active' => true]);

    $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/shipments", ['vehicle_id' => $vehicle->id])
        ->assertStatus(422);

    expect(Shipment::count())->toBe(0);
});

it('404s creating a shipment on another supplier\'s order', function () {
    ['order' => $order] = shipmentApiFixture();
    ['supplier' => $other] = shipmentApiFixture();

    $this->actingAs($other, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/shipments")
        ->assertNotFound();
});

it('auto-creates a shipment when the order is marked shipped, and only once', function () {
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();

    app(OrderService::class)->confirm($order, $supplier);
    app(CommandBus::class)->dispatch(new RecordOrderShipmentCommand($order->getKey(), $supplier->getKey()));

    expect(Shipment::where('order_id', $order->id)->count())->toBe(1);
});

it('does not add a second shipment on ship when one was created up front', function () {
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();

    $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/shipments")->assertCreated();

    app(OrderService::class)->confirm($order, $supplier);
    app(OrderService::class)->ship($order->fresh(), $supplier);

    expect(Shipment::where('order_id', $order->id)->count())->toBe(1);
});

/* ------------------------------------------------- listing / visibility */

it('lists shipments for the supplier and the carrier, but not outsiders', function () {
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();
    [$carrier, $carrierUser] = shipmentApiCarrier();
    $shipment = Shipment::factory()->create(['order_id' => $order->id, 'carrier_company_id' => $carrier->id]);
    Shipment::factory()->create(['order_id' => shipmentApiFixture()['order']->id]);

    foreach ([$supplier, $carrierUser] as $user) {
        $ids = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/shipments')->assertOk()->json('data'))->pluck('id');
        expect($ids->all())->toBe([$shipment->id]);
    }

    [, $outsider] = shipmentApiCarrier();
    $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/supplier/shipments/{$shipment->id}")->assertNotFound();
});

it('shows one shipment with checkpoint history', function () {
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();
    $shipment = Shipment::factory()->create(['order_id' => $order->id]);
    $shipment->checkpointUpdates()->create(['tracking_token' => str()->random(48), 'status' => 'dispatched']);

    $this->actingAs($supplier, 'sanctum')
        ->getJson("/api/v1/supplier/shipments/{$shipment->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.checkpoints')
        ->assertJsonPath('data.current_status', 'dispatched')
        ->assertJsonPath('data.tracking_url', fn ($url) => str_contains((string) $url, '/track/'));
});

/* --------------------------------------------------------- checkpoints */

it('records a checkpoint as the carrier and replays idempotently by client_event_id', function () {
    ['order' => $order] = shipmentApiFixture();
    [$carrier, $carrierUser] = shipmentApiCarrier();
    $shipment = Shipment::factory()->create(['order_id' => $order->id, 'carrier_company_id' => $carrier->id]);
    $payload = ['status' => TrackingCheckpointStatus::Dispatched->value, 'location' => 'Douala', 'client_event_id' => 'evt-123'];

    $first = $this->actingAs($carrierUser, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", $payload)
        ->assertCreated()->assertJsonPath('replayed', false);

    $this->actingAs($carrierUser, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", $payload)
        ->assertOk()->assertJsonPath('replayed', true)
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(CheckpointUpdate::forTrackable($shipment)->count())->toBe(1)
        ->and(CheckpointUpdate::forTrackable($shipment)->first()->recorded_by)->toBe($carrierUser->id);
});

it('forbids checkpoints from an unrelated company and validates status', function () {
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();
    $shipment = Shipment::factory()->create(['order_id' => $order->id]);
    [, $outsider] = shipmentApiCarrier();

    $this->actingAs($outsider, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", ['status' => 'delivered'])
        ->assertNotFound();

    $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", ['status' => 'teleported'])
        ->assertStatus(422);

    expect(CheckpointUpdate::forTrackable($shipment)->count())->toBe(0);
});

/* ------------------------------------------------------- fleet delete */

it('deletes an idle vehicle and driver but 409s while assigned to an in-transit shipment', function () {
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();
    [$carrier, $carrierUser] = shipmentApiCarrier();
    $busy = Vehicle::factory()->create(['company_id' => $carrier->id]);
    $idle = Vehicle::factory()->create(['company_id' => $carrier->id]);
    $busyDriver = Driver::factory()->create(['company_id' => $carrier->id]);
    $idleDriver = Driver::factory()->create(['company_id' => $carrier->id]);

    app(OrderService::class)->confirm($order, $supplier);
    Shipment::factory()->create([
        'order_id' => $order->id, 'vehicle_id' => $busy->id, 'driver_id' => $busyDriver->id, 'carrier_company_id' => $carrier->id,
    ]);
    app(OrderService::class)->ship($order->fresh(), $supplier);

    $this->actingAs($carrierUser, 'sanctum')->deleteJson("/api/v1/supplier/fleet/vehicles/{$busy->id}")
        ->assertStatus(409)->assertJsonPath('error.code', 'fleet_in_use');
    $this->actingAs($carrierUser, 'sanctum')->deleteJson("/api/v1/supplier/fleet/drivers/{$busyDriver->id}")
        ->assertStatus(409);

    $this->actingAs($carrierUser, 'sanctum')->deleteJson("/api/v1/supplier/fleet/vehicles/{$idle->id}")->assertNoContent();
    $this->actingAs($carrierUser, 'sanctum')->deleteJson("/api/v1/supplier/fleet/drivers/{$idleDriver->id}")->assertNoContent();

    expect(Vehicle::find($idle->id))->toBeNull()->and(Driver::find($idleDriver->id))->toBeNull()
        ->and(Vehicle::find($busy->id))->not->toBeNull();

    // Once delivered, the vehicle is free to delete.
    app(OrderService::class)->deliver($order->fresh(), $supplier);
    $this->actingAs($carrierUser, 'sanctum')->deleteJson("/api/v1/supplier/fleet/vehicles/{$busy->id}")->assertNoContent();
});

it('404s deleting another company\'s vehicle', function () {
    [, $carrierUser] = shipmentApiCarrier();
    [$other] = shipmentApiCarrier();
    $vehicle = Vehicle::factory()->create(['company_id' => $other->id]);

    $this->actingAs($carrierUser, 'sanctum')->deleteJson("/api/v1/supplier/fleet/vehicles/{$vehicle->id}")->assertNotFound();
});

/* --------------------------------------------------------- exporter panel */

it('creates a shipment from the exporter Orders table action with an own-fleet vehicle', function () {
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
    ['order' => $order, 'supplier' => $supplier, 'company' => $company] = shipmentApiFixture();
    $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'is_active' => true]);

    $this->actingAs($supplier);

    Livewire::test(ListOrders::class)
        ->callTableAction('createShipment', $order, data: ['vehicle_id' => $vehicle->id, 'origin' => 'Douala'])
        ->assertNotified();

    $shipment = Shipment::where('order_id', $order->id)->sole();
    expect($shipment->vehicle_id)->toBe($vehicle->id)
        ->and($shipment->carrier_company_id)->toBe($company->id);
});

it('scopes the exporter ShipmentResource to carrier or supplier', function () {
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
    ['order' => $order] = shipmentApiFixture();
    [$carrier, $carrierUser] = shipmentApiCarrier();
    $mine = Shipment::factory()->create(['order_id' => $order->id, 'carrier_company_id' => $carrier->id]);
    $theirs = Shipment::factory()->create(['order_id' => shipmentApiFixture()['order']->id]);
    $mine->checkpointUpdates()->create(['tracking_token' => str()->random(48), 'status' => 'in_transit', 'location' => 'Edea']);

    $this->actingAs($carrierUser);

    Livewire::test(ListShipments::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    Livewire::test(ViewShipment::class, ['record' => $mine->waybill_number])
        ->assertOk()
        ->assertSee('Edea');
});

/* -------------------------------------------- round-2: edit / notify / photo */

it('lets the supplier assign a partner carrier vehicle to an existing shipment via PATCH and notifies the carrier', function () {
    NotificationFacade::fake();
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();
    [$carrier, $carrierUser] = shipmentApiCarrier();
    $vehicle = Vehicle::factory()->create(['company_id' => $carrier->id, 'is_active' => true]);
    $shipment = app(ShipmentService::class)->ensureForOrder($order);
    expect($shipment->carrier_company_id)->toBeNull();

    $this->actingAs($supplier, 'sanctum')
        ->patchJson("/api/v1/supplier/shipments/{$shipment->id}", ['vehicle_id' => $vehicle->id, 'destination' => 'Yaoundé'])
        ->assertOk()
        ->assertJsonPath('data.carrier_company.id', $carrier->id)
        ->assertJsonPath('data.vehicle.id', $vehicle->id)
        ->assertJsonPath('data.destination', 'Yaoundé');

    NotificationFacade::assertSentTo($carrierUser, ShipmentAssignedNotification::class,
        fn ($n, array $channels) => in_array('mail', $channels, true) && str_contains($n->shipmentUrl(), '/shipments/'));
});

it('does not send a carrier notification for an own-fleet assignment', function () {
    NotificationFacade::fake();
    ['order' => $order, 'supplier' => $supplier, 'company' => $company] = shipmentApiFixture();
    $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'is_active' => true]);

    $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/shipments", ['vehicle_id' => $vehicle->id])
        ->assertCreated();

    NotificationFacade::assertNotSentTo($supplier, ShipmentAssignedNotification::class);
});

it('notifies the carrier when a shipment is created with a partner carrier', function () {
    NotificationFacade::fake();
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();
    [$carrier, $carrierUser] = shipmentApiCarrier();

    $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/shipments", ['carrier_company_id' => $carrier->id])
        ->assertCreated();

    NotificationFacade::assertSentTo($carrierUser, ShipmentAssignedNotification::class);
});

it('rejects PATCH from the carrier (403) and from strangers (404), and validates like creation', function () {
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();
    [$carrier, $carrierUser] = shipmentApiCarrier();
    $shipment = Shipment::factory()->create(['order_id' => $order->id, 'carrier_company_id' => $carrier->id]);
    ['supplier' => $stranger] = shipmentApiFixture();
    $originBefore = $shipment->origin;

    $this->actingAs($carrierUser, 'sanctum')
        ->patchJson("/api/v1/supplier/shipments/{$shipment->id}", ['origin' => 'X'])
        ->assertForbidden()->assertJsonPath('error.code', 'not_shipment_supplier');

    $this->actingAs($stranger, 'sanctum')
        ->patchJson("/api/v1/supplier/shipments/{$shipment->id}", ['origin' => 'X'])
        ->assertNotFound();

    $foreign = Vehicle::factory()->create(['company_id' => Company::factory()->create()->id, 'is_active' => true]);
    $this->actingAs($supplier, 'sanctum')
        ->patchJson("/api/v1/supplier/shipments/{$shipment->id}", ['vehicle_id' => $foreign->id])
        ->assertStatus(422);

    expect($shipment->refresh()->origin)->toBe($originBefore);
});

it('notifies supplier users and the buyer on a carrier delivered checkpoint without changing order status', function () {
    NotificationFacade::fake();
    ['order' => $order, 'supplier' => $supplier, 'buyer' => $buyer] = shipmentApiFixture();
    [$carrier, $carrierUser] = shipmentApiCarrier();
    $shipment = Shipment::factory()->create(['order_id' => $order->id, 'carrier_company_id' => $carrier->id]);
    $statusBefore = $order->refresh()->status;

    $this->actingAs($carrierUser, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", ['status' => 'delivered'])
        ->assertCreated();

    NotificationFacade::assertSentTo($supplier, ShipmentDeliveredNotification::class, fn ($n) => $n->audience === 'supplier');
    NotificationFacade::assertSentTo($buyer, ShipmentDeliveredNotification::class, fn ($n) => $n->audience === 'buyer');
    NotificationFacade::assertNotSentTo($carrierUser, ShipmentDeliveredNotification::class);
    expect($order->refresh()->status)->toBe($statusBefore);
});

it('notifies on a delivered checkpoint recorded via the web capture flow too', function () {
    NotificationFacade::fake();
    ['order' => $order, 'buyer' => $buyer] = shipmentApiFixture();
    [$carrier, $carrierUser] = shipmentApiCarrier();
    $shipment = Shipment::factory()->create(['order_id' => $order->id, 'carrier_company_id' => $carrier->id]);

    $this->actingAs($carrierUser)
        ->postJson(route('logistics.checkpoints.store', $shipment), ['status' => 'delivered'])
        ->assertCreated();

    NotificationFacade::assertSentTo($buyer, ShipmentDeliveredNotification::class);
});

it('coalesces in-transit buyer notifications to one per shipment per 6h', function () {
    NotificationFacade::fake();
    ['order' => $order, 'buyer' => $buyer] = shipmentApiFixture();
    [$carrier, $carrierUser] = shipmentApiCarrier();
    $shipment = Shipment::factory()->create(['order_id' => $order->id, 'carrier_company_id' => $carrier->id]);

    foreach (['Edea', 'Pouma'] as $place) {
        $this->actingAs($carrierUser, 'sanctum')
            ->postJson("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", ['status' => 'in_transit', 'location' => $place])
            ->assertCreated();
    }
    NotificationFacade::assertSentToTimes($buyer, ShipmentUpdateNotification::class, 1);

    $this->travel(7)->hours();
    $this->actingAs($carrierUser, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", ['status' => 'in_transit'])
        ->assertCreated();
    NotificationFacade::assertSentToTimes($buyer, ShipmentUpdateNotification::class, 2);
});

it('stores an optional checkpoint photo privately and rejects oversize uploads', function () {
    Storage::fake('local');
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();
    $shipment = Shipment::factory()->create(['order_id' => $order->id]);

    $this->actingAs($supplier, 'sanctum')
        ->post("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", [
            'status' => 'dispatched',
            'photo' => UploadedFile::fake()->image('proof.jpg')->size(6000),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    $this->actingAs($supplier, 'sanctum')
        ->post("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", [
            'status' => 'dispatched',
            'photo' => UploadedFile::fake()->image('proof.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.has_photo', true)
        ->assertJsonMissingPath('data.photo_path');

    $checkpoint = CheckpointUpdate::forTrackable($shipment)->sole();
    Storage::disk('local')->assertExists($checkpoint->photo_path);
});

it('assigns a carrier from the exporter ShipmentResource action (supplier only)', function () {
    NotificationFacade::fake();
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
    ['order' => $order, 'supplier' => $supplier] = shipmentApiFixture();
    [$carrier, $carrierUser] = shipmentApiCarrier();
    $shipment = Shipment::factory()->create(['order_id' => $order->id]);

    $this->actingAs($supplier);
    Livewire::test(ListShipments::class)
        ->callTableAction('assign', $shipment, data: ['carrier_company_id' => $carrier->id, 'origin' => 'Douala'])
        ->assertNotified();

    expect($shipment->refresh()->carrier_company_id)->toBe($carrier->id)
        ->and($shipment->origin)->toBe('Douala');
    NotificationFacade::assertSentTo($carrierUser, ShipmentAssignedNotification::class);

    $shipment->update(['carrier_company_id' => $carrier->id]);
    $this->actingAs($carrierUser);
    Livewire::test(ListShipments::class)->assertTableActionHidden('assign', $shipment);
});

/* ------------------------------------------------------- carrier picker */

it('lists selectable logistics carriers for the supplier, searchable, excluding shut-down companies', function () {
    ['supplier' => $supplier] = shipmentApiFixture();
    $verified = Company::factory()->create(['type' => OrganisationType::Logistics, 'legal_name' => 'Douala Haulage SARL', 'status' => \App\Enums\CompanyStatus::Verified]);
    $pending = Company::factory()->create(['type' => OrganisationType::Logistics, 'legal_name' => 'Kribi Trucks', 'status' => \App\Enums\CompanyStatus::Pending]);
    $suspended = Company::factory()->create(['type' => OrganisationType::Logistics, 'legal_name' => 'Banned Lines', 'status' => \App\Enums\CompanyStatus::Suspended]);
    Company::factory()->create(['legal_name' => 'Not A Hauler']);

    $ids = collect($this->actingAs($supplier, 'sanctum')->getJson('/api/v1/supplier/carriers')
        ->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($verified->getKey(), $pending->getKey())
        ->not->toContain($suspended->getKey());

    $this->actingAs($supplier, 'sanctum')->getJson('/api/v1/supplier/carriers?q=douala')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $verified->getKey())
        ->assertJsonPath('data.0.verified', true);
});
