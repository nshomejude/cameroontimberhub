<?php

use App\Models\Species;

it('persists the knowledge-system fields added for the SEO authority spec', function () {
    $species = Species::factory()->create([
        'french_name' => 'Iroko',
        'taxonomy' => ['family' => 'Moraceae', 'genus' => 'Milicia', 'species' => 'excelsa', 'order' => 'Rosales'],
        'workability' => 'Works well with both hand and machine tools; moderate blunting effect on cutters.',
        'drying_behaviour' => 'Dries slowly with little degrade; low shrinkage.',
        'treatments' => ['Kiln drying', 'Preservative treatment rarely required (naturally durable)'],
        'grades_available' => ['FAS', 'Select', 'Standard'],
        'eudr_risk_note' => null,
        'authoritative_sources' => [
            ['title' => 'CITES Species Database', 'publisher' => 'CITES Secretariat', 'url' => 'https://cites.org', 'accessed_date' => '2026-08-01'],
        ],
    ]);

    $fresh = $species->fresh();

    expect($fresh->french_name)->toBe('Iroko')
        // toEqual, not toBe: jsonb stores object keys unordered, so the key
        // order that comes back is Postgres's, not ours.
        ->and($fresh->taxonomy)->toEqual(['family' => 'Moraceae', 'genus' => 'Milicia', 'species' => 'excelsa', 'order' => 'Rosales'])
        ->and($fresh->workability)->toContain('Works well')
        ->and($fresh->treatments)->toBeArray()
        ->and($fresh->grades_available)->toBe(['FAS', 'Select', 'Standard'])
        ->and($fresh->eudr_risk_note)->toBeNull()
        ->and($fresh->authoritative_sources)->toHaveCount(1);
});

it('defaults every new knowledge-system field to null, never a fabricated placeholder', function () {
    $species = Species::factory()->create();

    expect($species->fresh())
        ->french_name->toBeNull()
        ->taxonomy->toBeNull()
        ->workability->toBeNull()
        ->drying_behaviour->toBeNull()
        ->treatments->toBeNull()
        ->grades_available->toBeNull()
        ->eudr_risk_note->toBeNull()
        ->authoritative_sources->toBeNull();
});

it('renders an honest not-yet-assessed note when eudr_risk_note is null', function () {
    $species = Species::factory()->create(['slug' => 'eudr-note-test', 'is_published' => true]);

    $this->get(route('species.show', $species->slug))
        ->assertOk()
        ->assertSee('not yet assessed', false);
});
