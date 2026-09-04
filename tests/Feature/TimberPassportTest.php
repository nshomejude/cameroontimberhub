<?php

use App\Enums\TimberLotStatus;
use App\Models\Company;
use App\Models\TimberLot;

it('renders a public passport for a non-draft lot belonging to a publicly-visible company', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $lot = TimberLot::factory()->available()->for($company)->create();

    $response = $this->get(route('passport.show', $lot));

    $response->assertOk()
        ->assertSee($lot->lot_number)
        ->assertSee($lot->species->common_name)
        ->assertSee('Available');
});

it('404s for a draft lot even if the owning company is publicly visible', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $lot = TimberLot::factory()->for($company)->create(['status' => TimberLotStatus::Draft]);

    $this->get(route('passport.show', $lot))->assertNotFound();
});

it('404s for a lot belonging to a non-publicly-visible company', function () {
    $company = Company::factory()->create();
    $lot = TimberLot::factory()->available()->for($company)->create();

    $this->get(route('passport.show', $lot))->assertNotFound();
});

it('renders successfully when LotEvent/LotTransformation classes are unavailable or empty', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $lot = TimberLot::factory()->available()->for($company)->create();

    // Whether or not the concurrently-built classes exist yet, a lot with
    // no actual event/transformation rows must never error and must never
    // render an empty placeholder section.
    $response = $this->get(route('passport.show', $lot));

    $response->assertOk()
        ->assertDontSee('Traceability events')
        ->assertDontSee('Processing history');
});

it('renders successfully with real traceability event data when LotEvent exists', function () {
    if (! class_exists(\App\Models\LotEvent::class)) {
        $this->markTestSkipped('LotEvent has not landed in this worktree yet.');
    }

    $company = Company::factory()->publiclyVisible()->create();
    $lot = TimberLot::factory()->available()->for($company)->create();

    $lot->recordEvent(\App\Enums\LotEventType::cases()[0] ?? null, [
        'occurred_at' => now(),
    ]);

    $response = $this->get(route('passport.show', $lot));

    $response->assertOk()->assertSee('Traceability events');
});

it('never exposes precise GPS coordinates even when the lot has them set', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $lot = TimberLot::factory()->available()->for($company)->create([
        'origin_region' => null,
        'origin_latitude' => 4.123456,
        'origin_longitude' => 9.987654,
    ]);

    $response = $this->get(route('passport.show', $lot));

    $response->assertOk();
    $html = $response->getContent();

    expect($html)->not->toContain('4.123456')
        ->and($html)->not->toContain('9.987654');
});

it('renders a plot boundary summary without raw coordinates when a boundary is present', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $lot = TimberLot::factory()->available()->for($company)->create([
        'origin_boundary' => [
            'type' => 'Polygon',
            'coordinates' => [[
                [9.712345, 4.056789],
                [9.720000, 4.056789],
                [9.720000, 4.060000],
                [9.712345, 4.056789],
            ]],
        ],
    ]);

    $response = $this->get(route('passport.show', $lot));

    $response->assertOk()
        ->assertSee('Origin plot boundary recorded (4-point polygon)');

    $html = $response->getContent();

    expect($html)->not->toContain('9.712345')
        ->and($html)->not->toContain('4.056789');
});

it('does not render a plot boundary section when no boundary is present', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $lot = TimberLot::factory()->available()->for($company)->create([
        'origin_boundary' => null,
    ]);

    $response = $this->get(route('passport.show', $lot));

    $response->assertOk()->assertDontSee('Plot boundary');
});
