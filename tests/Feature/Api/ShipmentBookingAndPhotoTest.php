<?php

use App\Enums\OrganisationType;
use App\Enums\ShipmentCarrierStatus;
use App\Filament\Exporter\Resources\Shipments\Pages\ListShipments;
use App\Filament\Exporter\Resources\Shipments\Pages\ViewShipment;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\ShipmentAssignedNotification;
use App\Notifications\ShipmentBookingAcceptedNotification;
use App\Notifications\ShipmentBookingDeclinedNotification;
use App\Services\QuoteService;
use App\Services\ShipmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Owner decisions (logistics): (1) carrier booking acceptance alongside
 * direct assignment (Shipment::carrier_status); (2) buyers can view
 * checkpoint proof photos (API stream + 30-min signed web URL).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/** @return array{order: Order, supplier: User, company: Company, buyer: User, rfq: Rfq} */
function bookingFixture(): array
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

    return compact('order', 'supplier', 'company', 'buyer', 'rfq');
}

/** @return array{0: Company, 1: User} */
function bookingCarrier(): array
{
    $carrier = Company::factory()->create(['type' => OrganisationType::Logistics]);
    $user = User::factory()->create();
    $carrier->users()->attach($user, ['role' => 'member', 'is_primary' => true]);

    return [$carrier, $user];
}

function pendingBooking(Order $order, Company $carrier, array $extra = []): Shipment
{
    return app(ShipmentService::class)->createFromOrder($order, ['carrier_company_id' => $carrier->id, 'mode' => 'request', ...$extra]);
}

/* ------------------------------------------------------- carrier_status */

it('assigns a carrier directly by default (backward compatible) and notifies it as an assignment', function () {
    NotificationFacade::fake();
    ['order' => $order, 'supplier' => $supplier] = bookingFixture();
    [$carrier, $carrierUser] = bookingCarrier();

    $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/shipments", ['carrier_company_id' => $carrier->id])
        ->assertCreated()
        ->assertJsonPath('data.carrier_status', 'assigned')
        ->assertJsonPath('data.carrier_status_label', 'Assigned');

    NotificationFacade::assertSentTo($carrierUser, ShipmentAssignedNotification::class, fn ($n) => $n->bookingRequest === false);
});

it('creates a booking request with mode=request and notifies the carrier with an accept/decline CTA', function () {
    NotificationFacade::fake();
    ['order' => $order, 'supplier' => $supplier] = bookingFixture();
    [$carrier, $carrierUser] = bookingCarrier();

    $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/shipments", ['carrier_company_id' => $carrier->id, 'mode' => 'request'])
        ->assertCreated()
        ->assertJsonPath('data.carrier_status', 'pending');

    NotificationFacade::assertSentTo($carrierUser, ShipmentAssignedNotification::class, function ($n) use ($carrierUser) {
        return $n->bookingRequest === true
            && $n->toArray($carrierUser)['booking_request'] === true
            && str_contains($n->toMail($carrierUser)->subject, 'Booking request');
    });
});

it('leaves carrier_status null for own fleet and rejects an unknown mode', function () {
    ['order' => $order, 'supplier' => $supplier, 'company' => $company] = bookingFixture();
    $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'is_active' => true]);

    $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/shipments", ['vehicle_id' => $vehicle->id, 'mode' => 'request'])
        ->assertCreated()
        ->assertJsonPath('data.carrier_status', null);

    $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/shipments", ['mode' => 'bogus'])
        ->assertStatus(422);
});

it('lets the carrier accept a pending booking, notifies the supplier and then allows checkpoints', function () {
    NotificationFacade::fake();
    ['order' => $order, 'supplier' => $supplier] = bookingFixture();
    [$carrier, $carrierUser] = bookingCarrier();
    $shipment = pendingBooking($order, $carrier);

    // Pending: carrier can see it but not operate it.
    $this->actingAs($carrierUser, 'sanctum')
        ->getJson("/api/v1/supplier/shipments/{$shipment->id}")
        ->assertOk()->assertJsonPath('data.carrier_status', 'pending');
    $this->actingAs($carrierUser, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", ['status' => 'dispatched'])
        ->assertForbidden()->assertJsonPath('error.code', 'carrier_booking_not_active');
    $this->actingAs($carrierUser)->get(route('logistics.checkpoints.create', $shipment))->assertForbidden();

    // The supplier side may still record.
    $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", ['status' => 'dispatched'])
        ->assertCreated();

    $this->actingAs($carrierUser, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.carrier_status', 'accepted')
        ->assertJsonPath('data.carrier_company.id', $carrier->id);

    NotificationFacade::assertSentTo($supplier, ShipmentBookingAcceptedNotification::class);

    $this->actingAs($carrierUser, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/checkpoints", ['status' => 'in_transit'])
        ->assertCreated();

    // Answering twice is a conflict.
    $this->actingAs($carrierUser, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/accept")
        ->assertStatus(409)->assertJsonPath('error.code', 'booking_not_pending');
});

it('clears the carrier and its fleet on decline and notifies the supplier with the reason', function () {
    NotificationFacade::fake();
    ['order' => $order, 'supplier' => $supplier] = bookingFixture();
    [$carrier, $carrierUser] = bookingCarrier();
    $vehicle = Vehicle::factory()->create(['company_id' => $carrier->id, 'is_active' => true]);
    $shipment = pendingBooking($order, $carrier, ['vehicle_id' => $vehicle->id]);
    expect($shipment->vehicle_id)->toBe($vehicle->id);

    $this->actingAs($carrierUser, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/decline", ['reason' => 'No trucks this week'])
        ->assertOk()
        ->assertJsonPath('data.carrier_status', 'declined')
        ->assertJsonPath('data.carrier_company', null)
        ->assertJsonPath('data.vehicle', null)
        ->assertJsonPath('data.carrier_decline_reason', 'No trucks this week');

    $shipment->refresh();
    expect($shipment->carrier_company_id)->toBeNull()
        ->and($shipment->vehicle_id)->toBeNull()
        ->and($shipment->carrier_status)->toBe(ShipmentCarrierStatus::Declined);

    NotificationFacade::assertSentTo($supplier, ShipmentBookingDeclinedNotification::class,
        fn ($n) => $n->reason === 'No trucks this week' && $n->carrierName === $carrier->name);

    // The carrier no longer sees the shipment.
    $this->actingAs($carrierUser, 'sanctum')->getJson("/api/v1/supplier/shipments/{$shipment->id}")->assertNotFound();
});

it('only lets carrier members answer a booking request', function () {
    ['order' => $order, 'supplier' => $supplier] = bookingFixture();
    [$carrier, $carrierUser] = bookingCarrier();
    $shipment = pendingBooking($order, $carrier);

    $this->actingAs($supplier, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/accept")
        ->assertForbidden()->assertJsonPath('error.code', 'not_shipment_carrier');

    [, $stranger] = bookingCarrier();
    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$shipment->id}/decline")
        ->assertNotFound();

    // A directly assigned shipment has nothing to answer.
    $direct = app(ShipmentService::class)->createFromOrder($order, ['carrier_company_id' => $carrier->id]);
    $this->actingAs($carrierUser, 'sanctum')
        ->postJson("/api/v1/supplier/shipments/{$direct->id}/decline")
        ->assertStatus(409);
});

it('turns a pending request into a direct assignment via PATCH mode=assign', function () {
    NotificationFacade::fake();
    ['order' => $order, 'supplier' => $supplier] = bookingFixture();
    [$carrier, $carrierUser] = bookingCarrier();
    $shipment = pendingBooking($order, $carrier);

    $this->actingAs($supplier, 'sanctum')
        ->patchJson("/api/v1/supplier/shipments/{$shipment->id}", ['mode' => 'assign'])
        ->assertOk()
        ->assertJsonPath('data.carrier_status', 'assigned');

    NotificationFacade::assertSentTo($carrierUser, ShipmentAssignedNotification::class, fn ($n) => $n->bookingRequest === false);
});

it('re-requests a booking after a decline via PATCH with a new carrier', function () {
    ['order' => $order, 'supplier' => $supplier] = bookingFixture();
    [$carrier, $carrierUser] = bookingCarrier();
    [$other] = bookingCarrier();
    $shipment = pendingBooking($order, $carrier);
    app(ShipmentService::class)->declineBooking($shipment, $carrierUser, null);

    $this->actingAs($supplier, 'sanctum')
        ->patchJson("/api/v1/supplier/shipments/{$shipment->id}", ['carrier_company_id' => $other->id, 'mode' => 'request'])
        ->assertOk()
        ->assertJsonPath('data.carrier_status', 'pending')
        ->assertJsonPath('data.carrier_decline_reason', null);
});

it('exposes carrier_status to the buyer tracking API', function () {
    ['order' => $order, 'buyer' => $buyer] = bookingFixture();
    [$carrier] = bookingCarrier();
    pendingBooking($order, $carrier);

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/orders/{$order->reference_code}/shipments")
        ->assertOk()
        ->assertJsonPath('data.0.carrier_status', 'pending')
        ->assertJsonPath('data.0.carrier_status_label', 'Awaiting carrier acceptance');
});

it('lets carriers accept and decline from the exporter panel and suppliers request a booking', function () {
    NotificationFacade::fake();
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
    ['order' => $order, 'supplier' => $supplier] = bookingFixture();
    [$carrier, $carrierUser] = bookingCarrier();
    $shipment = Shipment::factory()->create(['order_id' => $order->id]);

    $this->actingAs($supplier);
    Livewire::test(ListShipments::class)
        ->assertTableActionHidden('acceptBooking', $shipment)
        ->callTableAction('assign', $shipment, data: ['carrier_company_id' => $carrier->id, 'mode' => 'request'])
        ->assertNotified();
    expect($shipment->refresh()->carrier_status)->toBe(ShipmentCarrierStatus::Pending);
    Livewire::test(ListShipments::class)->assertTableActionHidden('acceptBooking', $shipment);

    $this->actingAs($carrierUser);
    Livewire::test(ListShipments::class)
        ->assertTableActionHidden('checkpoint', $shipment)
        ->callTableAction('acceptBooking', $shipment)
        ->assertNotified();
    expect($shipment->refresh()->carrier_status)->toBe(ShipmentCarrierStatus::Accepted);
    NotificationFacade::assertSentTo($supplier, ShipmentBookingAcceptedNotification::class);

    $second = pendingBooking($order, $carrier);
    Livewire::test(ListShipments::class)
        ->callTableAction('declineBooking', $second, data: ['reason' => 'Fully booked'])
        ->assertNotified();
    expect($second->refresh()->carrier_status)->toBe(ShipmentCarrierStatus::Declined)
        ->and($second->carrier_decline_reason)->toBe('Fully booked');
});

it('backfills legacy third-party carriers as assigned when the migration runs', function () {
    ['order' => $order, 'company' => $company] = bookingFixture();
    [$carrier] = bookingCarrier();
    $third = Shipment::factory()->create(['order_id' => $order->id, 'carrier_company_id' => $carrier->id]);
    $own = Shipment::factory()->create(['order_id' => $order->id, 'carrier_company_id' => $company->id]);

    $migration = require database_path('migrations/2026_10_01_150000_add_carrier_status_to_shipments_table.php');
    $migration->down();
    $migration->up();

    expect($third->refresh()->carrier_status)->toBe(ShipmentCarrierStatus::Assigned)
        ->and($own->refresh()->carrier_status)->toBeNull();
});

/* ------------------------------------------------------ checkpoint photos */

function shipmentWithPhoto(Order $order): array
{
    $shipment = Shipment::factory()->create(['order_id' => $order->id]);
    $path = UploadedFile::fake()->image('proof.png')->store('checkpoint-photos/'.$shipment->id, 'local');
    $withPhoto = $shipment->checkpointUpdates()->create([
        'tracking_token' => str()->random(48), 'status' => 'in_transit', 'location' => 'Edea', 'photo_path' => $path,
    ]);
    $noPhoto = $shipment->checkpointUpdates()->create([
        'tracking_token' => str()->random(48), 'status' => 'dispatched', 'location' => 'Douala',
    ]);

    return [$shipment, $withPhoto, $noPhoto];
}

it('streams a checkpoint photo to the order buyer over the API with photo_url in the payload', function () {
    Storage::fake('local');
    ['order' => $order, 'buyer' => $buyer] = bookingFixture();
    [$shipment, $withPhoto, $noPhoto] = shipmentWithPhoto($order);
    $url = "/api/v1/orders/{$order->reference_code}/shipments/{$shipment->id}/checkpoints/{$withPhoto->id}/photo";

    $response = $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/orders/{$order->reference_code}/shipments")->assertOk();
    $checkpoints = collect($response->json('data.0.checkpoints'))->keyBy('id');
    expect($checkpoints[$withPhoto->id]['photo_url'])->toEndWith($url)
        ->and($checkpoints[$noPhoto->id]['photo_url'])->toBeNull()
        ->and($checkpoints[$withPhoto->id])->not->toHaveKey('photo_path');

    $photo = $this->actingAs($buyer, 'sanctum')->get($url)->assertOk();
    expect($photo->headers->get('Content-Type'))->toBe('image/png')
        ->and($photo->headers->get('Cache-Control'))->toContain('private');

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/orders/{$order->reference_code}/shipments/{$shipment->id}/checkpoints/{$noPhoto->id}/photo")
        ->assertNotFound();

    // Another buyer, or a shipment of another order: 404.
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson($url)->assertNotFound();
    $otherOrder = bookingFixture()['order'];
    [$foreign] = shipmentWithPhoto($otherOrder);
    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/orders/{$order->reference_code}/shipments/{$foreign->id}/checkpoints/{$withPhoto->id}/photo")
        ->assertNotFound();
});

it('streams a checkpoint photo to the supplier and carrier over the supplier API', function () {
    Storage::fake('local');
    ['order' => $order, 'supplier' => $supplier] = bookingFixture();
    [$shipment, $withPhoto] = shipmentWithPhoto($order);

    $response = $this->actingAs($supplier, 'sanctum')->getJson("/api/v1/supplier/shipments/{$shipment->id}")->assertOk();
    $photoUrl = collect($response->json('data.checkpoints'))->firstWhere('id', $withPhoto->id)['photo_url'];
    expect($photoUrl)->toEndWith("/api/v1/supplier/shipments/{$shipment->id}/checkpoints/{$withPhoto->id}/photo");

    $this->actingAs($supplier, 'sanctum')->get($photoUrl)->assertOk()->assertHeader('Content-Type', 'image/png');

    // The supplier's order-tracking view links the supplier stream, not the buyer one.
    $tracking = $this->actingAs($supplier, 'sanctum')->getJson("/api/v1/supplier/orders/{$order->reference_code}/shipments")->assertOk();
    expect(collect($tracking->json('data.0.checkpoints'))->firstWhere('id', $withPhoto->id)['photo_url'])->toBe($photoUrl);

    [, $stranger] = bookingCarrier();
    $this->actingAs($stranger, 'sanctum')->getJson($photoUrl)->assertNotFound();
});

it('serves checkpoint photos on the web only through a valid signed URL', function () {
    Storage::fake('local');
    ['order' => $order] = bookingFixture();
    [$shipment, $withPhoto, $noPhoto] = shipmentWithPhoto($order);
    $service = app(ShipmentService::class);

    $this->get(route('shipments.checkpoints.photo', ['shipment' => $shipment->id, 'checkpoint' => $withPhoto->id]))
        ->assertForbidden();

    $signed = $service->photoSignedUrl($shipment, $withPhoto);
    $this->get($signed)->assertOk()->assertHeader('Content-Type', 'image/png');

    expect($service->photoSignedUrl($shipment, $noPhoto))->toBeNull();

    $this->travel(31)->minutes();
    $this->get($signed)->assertForbidden();
});

it('shows checkpoint photo thumbnails on the buyer order page', function () {
    Storage::fake('local');
    ['order' => $order, 'buyer' => $buyer, 'rfq' => $rfq] = bookingFixture();
    [$shipment] = shipmentWithPhoto($order);

    $this->actingAs($buyer)
        ->get(route('buyer.rfq.order', $rfq))
        ->assertOk()
        ->assertSee($shipment->waybill_number)
        ->assertSee('data-checkpoint-photo', false)
        ->assertSee('/shipments/'.$shipment->id.'/checkpoints/', false);
});

it('shows checkpoint photos on the exporter shipment view', function () {
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
    ['order' => $order, 'supplier' => $supplier] = bookingFixture();
    [$shipment, $withPhoto] = shipmentWithPhoto($order);

    $this->actingAs($supplier);
    Livewire::test(ViewShipment::class, ['record' => $shipment->waybill_number])
        ->assertOk()
        ->assertSee('/shipments/'.$shipment->id.'/checkpoints/'.$withPhoto->id.'/photo', false);
});
