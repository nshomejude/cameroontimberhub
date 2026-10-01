<?php

use App\Support\Ops\OpsProbes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('reports healthy when the database and cache are reachable', function () {
    $this->getJson('/up/health')
        ->assertOk()
        ->assertJson(['status' => 'ok'])
        ->assertJsonStructure(['status', 'checks' => ['database', 'cache', 'queue', 'scheduler'], 'time']);
});

it('does not require authentication', function () {
    $this->getJson('/up/health')->assertOk();
});

it('returns 503 when a check fails', function () {
    config()->set('cache.default', 'nonexistent-store');

    $this->getJson('/up/health')
        ->assertStatus(503)
        ->assertJson(['status' => 'degraded']);
});

it('measures the backlog on the configured queue connection, not the jobs table', function () {
    config(['queue.default' => 'database']);

    $row = [
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ];
    DB::table('jobs')->insert($row);
    DB::table('jobs')->insert(array_merge($row, ['queue' => 'other']));

    expect(OpsProbes::queueSize())->toBe(1);

    config(['queue.default' => 'sync']);
    expect(OpsProbes::queueSize())->toBe(0);
});

it('passes the scheduler check with a fresh heartbeat', function () {
    $this->artisan('ops:scheduler-heartbeat')->assertSuccessful();

    $this->getJson('/up/health')->assertOk()->assertJsonPath('checks.scheduler', true);
});

it('returns 503 when the scheduler heartbeat is stale', function () {
    Cache::forever(OpsProbes::HEARTBEAT_KEY, now()->subMinutes(10)->getTimestamp());

    $this->getJson('/up/health')
        ->assertStatus(503)
        ->assertJsonPath('checks.scheduler', false);
});

it('fails the scheduler check in production when no heartbeat was ever recorded', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->getJson('/up/health')
        ->assertStatus(503)
        ->assertJsonPath('checks.scheduler', false);
});
