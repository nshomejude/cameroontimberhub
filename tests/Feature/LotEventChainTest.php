<?php

use App\Enums\LotEventType;
use App\Models\LotEvent;
use App\Models\TimberLot;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('produces a valid, verifiable hash chain when recording a sequence of events on a lot', function () {
    $actor = User::factory()->create();
    $lot = TimberLot::factory()->create();

    $this->actingAs($actor);

    $lot->recordEvent(LotEventType::SourceRegistered, ['location' => 'Yaoundé']);
    $lot->recordEvent(LotEventType::HarvestRecorded, ['location' => 'East Region']);
    $lot->recordEvent(LotEventType::TransportDispatched);

    $events = LotEvent::where('timber_lot_id', $lot->id)->orderBy('id')->get();

    expect($events)->toHaveCount(3)
        ->and($events[0]->previous_event_hash)->toBeNull()
        ->and($events[1]->previous_event_hash)->toBe($events[0]->event_hash)
        ->and($events[2]->previous_event_hash)->toBe($events[1]->event_hash)
        ->and($events[0]->event_hash)->toHaveLength(64);

    Artisan::call('lot-events:verify-chain', ['lot_number' => $lot->lot_number]);
    expect(Artisan::output())->toContain('Chain intact.');
});

it('refuses to update an existing lot event', function () {
    $lot = TimberLot::factory()->create();
    $event = $lot->recordEvent(LotEventType::SourceRegistered);

    expect(fn () => $event->update(['notes' => 'tampered']))
        ->toThrow(RuntimeException::class);
});

it('refuses to delete an existing lot event', function () {
    $lot = TimberLot::factory()->create();
    $event = $lot->recordEvent(LotEventType::SourceRegistered);

    expect(fn () => $event->delete())
        ->toThrow(RuntimeException::class);
});

it('detects a manually corrupted stored hash via the verify command', function () {
    $lot = TimberLot::factory()->create();
    $lot->recordEvent(LotEventType::SourceRegistered);
    $second = $lot->recordEvent(LotEventType::HarvestRecorded);

    // Bypass the model entirely -- a raw UPDATE, simulating tampering.
    DB::table('lot_events')->where('id', $second->id)->update(['event_hash' => str_repeat('0', 64)]);

    $exitCode = Artisan::call('lot-events:verify-chain', ['lot_number' => $lot->lot_number]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Chain broken');
});

it('records the authenticated actor id on TimberLot::recordEvent()', function () {
    $actor = User::factory()->create();
    $lot = TimberLot::factory()->create();

    $this->actingAs($actor);

    $event = $lot->recordEvent(LotEventType::QualityChecked);

    expect($event->actor_id)->toBe($actor->id);
});
