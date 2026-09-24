<?php

use App\Models\User;
use App\Notifications\MessageReceivedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\PaymentConfirmedNotification;
use App\Notifications\QuoteAcceptedNotification;
use App\Notifications\QuoteReceivedNotification;
use App\Notifications\RfqRoutedToExporter;
use Database\Seeders\DemoLoginSeeder;

/**
 * DemoLoginSeeder::seedNotifications() — every demo persona should have a
 * real, non-empty notification inbox after the seeder runs, built from the
 * same real RFQs/quotes/orders the other demo seeders already produce.
 */
beforeEach(function () {
    $this->seed(DemoLoginSeeder::class);
});

function demoPersona(string $key): User
{
    return User::where('email', config("demo.personas.{$key}.email"))->firstOrFail();
}

it('gives the demo buyer at least 3 notifications with a real reference and an unread one', function () {
    $buyer = demoPersona('buyer');

    expect($buyer->notifications()->count())->toBeGreaterThanOrEqual(3)
        ->and($buyer->unreadNotifications()->count())->toBeGreaterThan(0);

    $quoteReceived = $buyer->notifications()->where('type', QuoteReceivedNotification::class)->firstOrFail();
    expect($quoteReceived->data['reference'])->not->toBeNull()
        ->and($quoteReceived->data['screen'])->toBe('quote');

    $orderChanged = $buyer->notifications()->where('type', OrderStatusChangedNotification::class)->first();
    expect($orderChanged)->not->toBeNull()
        ->and($orderChanged->data['screen'])->toBe('order');

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk();

    $types = collect($response->json('data'))->pluck('type');
    expect($types)->toContain('quote_received');
});

it('gives the demo supplier an rfq_routed notification referencing the real routed RFQ', function () {
    $supplier = demoPersona('supplier');

    $notification = $supplier->notifications()->where('type', RfqRoutedToExporter::class)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['type'])->toBe('rfq_routed')
        ->and($notification->data['reference'])->not->toBeNull();

    $rfqExists = \App\Models\Rfq::where('reference_code', $notification->data['reference'])->exists();
    expect($rfqExists)->toBeTrue();
});

it('either seeds a fitting notification for the logistics persona or documents there is none', function () {
    $logistics = demoPersona('logistics');

    // No notification class currently models a company/fleet document event
    // (DocumentUploadedNotification requires a real Order, which the
    // logistics persona's company is never a party to), so nothing is
    // forced here — see DemoLoginSeeder::seedLogisticsNotifications().
    expect($logistics->notifications()->count())->toBe(0);
});

it('does not duplicate notifications when the seeder runs twice', function () {
    $buyer = demoPersona('buyer');
    $supplier = demoPersona('supplier');

    $buyerCountBefore = $buyer->notifications()->count();
    $supplierCountBefore = $supplier->notifications()->count();

    $this->seed(DemoLoginSeeder::class);

    expect($buyer->fresh()->notifications()->count())->toBe($buyerCountBefore)
        ->and($supplier->fresh()->notifications()->count())->toBe($supplierCountBefore);
});

it('reflects a realistic non-zero, non-total unread count for the buyer and supplier', function () {
    $buyer = demoPersona('buyer');
    $supplier = demoPersona('supplier');

    foreach ([$buyer, $supplier] as $persona) {
        $total = $persona->notifications()->count();
        $unread = $persona->unreadNotifications()->count();

        expect($unread)->toBeGreaterThan(0);

        if ($total > 1) {
            expect($unread)->toBeLessThan($total);
        }

        $this->actingAs($persona, 'sanctum')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', $unread);
    }
});
