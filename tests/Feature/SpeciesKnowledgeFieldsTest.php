<?php

use App\Models\Species;

/** A realistic sourced-and-dated note — the only kind this field should ever hold. */
const EUDR_NOTE = "Low risk per the EU Deforestation Regulation's country benchmarking of 2026-06-01; verify the operator's due-diligence statement (Annex II & IV) per consignment.";

it('persists the knowledge-system fields added for the SEO authority spec', function () {
    $species = Species::factory()->create([
        'french_name' => 'Iroko',
        'taxonomy' => ['family' => 'Moraceae', 'genus' => 'Milicia', 'species' => 'excelsa', 'order' => 'Rosales'],
        'workability' => 'Works well with both hand and machine tools; moderate blunting effect on cutters.',
        'drying_behaviour' => 'Dries slowly with little degrade; low shrinkage.',
        'treatments' => ['Kiln drying', 'Preservative treatment rarely required (naturally durable)'],
        'grades_available' => ['FAS', 'Select', 'Standard'],
        'eudr_risk_note' => EUDR_NOTE,
        'authoritative_sources' => [
            ['title' => 'CITES Species Database', 'publisher' => 'CITES Secretariat', 'url' => 'https://cites.org', 'accessed_date' => '2026-08-01'],
        ],
    ]);

    $fresh = $species->fresh();

    expect($fresh->french_name)->toBe('Iroko')
        // toEqual, not toBe: jsonb stores object keys unordered, so the key
        // order that comes back is Postgres's, not ours.
        ->and($fresh->taxonomy)->toEqual(['family' => 'Moraceae', 'genus' => 'Milicia', 'species' => 'excelsa', 'order' => 'Rosales'])
        ->and($fresh->workability)->toBe('Works well with both hand and machine tools; moderate blunting effect on cutters.')
        ->and($fresh->drying_behaviour)->toBe('Dries slowly with little degrade; low shrinkage.')
        ->and($fresh->treatments)->toBe(['Kiln drying', 'Preservative treatment rarely required (naturally durable)'])
        ->and($fresh->grades_available)->toBe(['FAS', 'Select', 'Standard'])
        ->and($fresh->eudr_risk_note)->toBe(EUDR_NOTE)
        ->and($fresh->authoritative_sources)->toEqual([
            ['title' => 'CITES Species Database', 'publisher' => 'CITES Secretariat', 'url' => 'https://cites.org', 'accessed_date' => '2026-08-01'],
        ]);
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
        ->assertSee('not yet assessed')
        ->assertDontSee('Low risk per the EU Deforestation Regulation');
});

it('renders the recorded eudr_risk_note instead of the fallback when one is set', function () {
    $species = Species::factory()->create([
        'slug' => 'eudr-note-populated',
        'is_published' => true,
        'eudr_risk_note' => EUDR_NOTE,
    ]);

    $this->get(route('species.show', $species->slug))
        ->assertOk()
        ->assertSee(EUDR_NOTE)
        ->assertDontSee('not yet assessed');
});
