<?php

use App\Enums\TrackingCheckpointStatus;
use App\Models\CheckpointUpdate;
use App\Models\Company;
use App\Models\Concerns\HasCheckpointUpdates;
use App\Services\CheckpointTracker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a checkpoint update row with a polymorphic trackable', function () {
    $company = Company::factory()->create();

    $checkpoint = CheckpointUpdate::create([
        'trackable_type' => Company::class,
        'trackable_id' => $company->id,
        'tracking_token' => str()->random(48),
        'status' => TrackingCheckpointStatus::Dispatched->value,
        'location' => 'Douala Port, Gate 3',
        'latitude' => 4.0483,
        'longitude' => 9.7043,
        'notes' => 'Left the warehouse on schedule.',
    ]);

    expect($checkpoint->exists)->toBeTrue()
        ->and($checkpoint->status)->toBe(TrackingCheckpointStatus::Dispatched)
        ->and((float) $checkpoint->latitude)->toBe(4.0483)
        ->and($checkpoint->trackable_type)->toBe(Company::class)
        ->and($checkpoint->trackable_id)->toBe($company->id);
});

it('exposes checkpointUpdates and latestCheckpoint via the HasCheckpointUpdates trait', function () {
    $trackable = new class extends Model
    {
        use HasCheckpointUpdates;

        protected $table = 'companies';

        protected $guarded = ['id'];
    };

    $company = Company::factory()->create();
    $subject = $trackable::query()->find($company->id);

    CheckpointUpdate::factory()->for($subject, 'trackable')->create([
        'status' => TrackingCheckpointStatus::Dispatched->value,
        'created_at' => now()->subHour(),
    ]);
    $latest = CheckpointUpdate::factory()->for($subject, 'trackable')->create([
        'status' => TrackingCheckpointStatus::InTransit->value,
    ]);

    expect($subject->checkpointUpdates()->count())->toBe(2)
        ->and($subject->latestCheckpoint()->id)->toBe($latest->id);
});

it('finds a trackable history by tracking token and returns an ordered public payload', function () {
    $company = Company::factory()->create();

    CheckpointUpdate::factory()->create([
        'trackable_type' => Company::class,
        'trackable_id' => $company->id,
        'tracking_token' => 'shared-token-abc',
        'status' => TrackingCheckpointStatus::Dispatched->value,
        'location' => 'Douala Port',
        'created_at' => now()->subHours(2),
    ]);
    CheckpointUpdate::factory()->create([
        'trackable_type' => Company::class,
        'trackable_id' => $company->id,
        'tracking_token' => 'shared-token-abc',
        'status' => TrackingCheckpointStatus::InTransit->value,
        'location' => 'Yaounde Hub',
        'photo_path' => 'checkpoints/photo.jpg',
        'created_at' => now()->subHour(),
    ]);

    $tracker = app(CheckpointTracker::class);

    $history = $tracker->findByToken('shared-token-abc');
    expect($history)->toHaveCount(2);

    $payload = $tracker->publicPayload($history);

    expect($payload)->toHaveCount(2)
        ->and($payload[0]['status'])->toBe('Dispatched')
        ->and($payload[1]['status'])->toBe('In transit')
        ->and($payload[1]['has_photo'])->toBeTrue()
        ->and($payload[0]['has_photo'])->toBeFalse()
        ->and($payload[0])->not->toHaveKey('photo_path')
        ->and($payload[0])->not->toHaveKey('id')
        ->and($payload[0])->not->toHaveKey('trackable_id');
});

it('returns an empty collection for an unknown tracking token', function () {
    $tracker = app(CheckpointTracker::class);

    expect($tracker->findByToken('does-not-exist'))->toHaveCount(0);
});

it('shows checkpoint history on the public tracking page for a valid token', function () {
    $company = Company::factory()->create();

    CheckpointUpdate::factory()->create([
        'trackable_type' => Company::class,
        'trackable_id' => $company->id,
        'tracking_token' => 'public-track-token-1',
        'status' => TrackingCheckpointStatus::Dispatched->value,
        'location' => 'Douala Port',
    ]);

    $response = $this->get('/track/public-track-token-1');

    $response->assertOk();
    $response->assertSee('Dispatched');
    $response->assertSee('Douala Port');
});

it('shows a generic not-found message for an unknown tracking token', function () {
    $response = $this->get('/track/does-not-exist-token');

    $response->assertOk();
    $response->assertSee('No tracking information', escape: false);
});
