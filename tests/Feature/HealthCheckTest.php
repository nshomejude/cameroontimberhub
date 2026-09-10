<?php

it('reports healthy when the database and cache are reachable', function () {
    $this->getJson('/up/health')
        ->assertOk()
        ->assertJson(['status' => 'ok'])
        ->assertJsonStructure(['status', 'checks' => ['database', 'cache', 'queue'], 'time']);
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
