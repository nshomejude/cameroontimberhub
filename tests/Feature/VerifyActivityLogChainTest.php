<?php

use App\Models\Certificate;
use App\Models\ChainedActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('reports the chain intact when nothing has been tampered with', function () {
    $actor = User::factory()->create();
    $certificate = Certificate::factory()->create();
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_a');
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_b');

    $this->artisan('activitylog:verify-chain')
        ->assertSuccessful()
        ->expectsOutputToContain('Chain intact');
});

it('detects and reports the first row where the chain breaks', function () {
    $actor = User::factory()->create();
    $certificate = Certificate::factory()->create();
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_a');
    $tampered = ChainedActivity::latest('id')->first();
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_b');

    // Simulate tampering: rewrite a historical row's description without
    // recomputing its hash (exactly what an attacker with raw DB access,
    // bypassing Eloquent, would do).
    DB::table('activity_log')->where('id', $tampered->id)->update(['description' => 'event_a_altered']);

    $this->artisan('activitylog:verify-chain')
        ->assertFailed()
        ->expectsOutputToContain((string) $tampered->id);
});
