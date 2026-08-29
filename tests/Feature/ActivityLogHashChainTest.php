<?php

use App\Models\Certificate;
use App\Models\ChainedActivity;
use App\Models\User;

it('computes a hash and chains prev_hash to the immediately preceding activity row, globally in insertion order', function () {
    $actor = User::factory()->create();
    $certificate = Certificate::factory()->create();

    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_a');
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_b');

    $rows = ChainedActivity::orderBy('id')->get();
    $a = $rows->firstWhere('description', 'event_a');
    $b = $rows->firstWhere('description', 'event_b');

    expect($a->hash)->not->toBeNull()->and($a->hash)->toHaveLength(64)
        ->and($b->prev_hash)->toBe($a->hash);
});

it('produces a different hash when a chained field actually differs between two otherwise-similar rows', function () {
    $actor = User::factory()->create();
    $certificate = Certificate::factory()->create();

    activity('cert')->performedOn($certificate)->causedBy($actor)->log('same_description');
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('same_description');

    $rows = ChainedActivity::where('description', 'same_description')->orderBy('id')->get();

    expect($rows[0]->hash)->not->toBe($rows[1]->hash);
});
