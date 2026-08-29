<?php

use App\Enums\RfqType;
use App\Models\Rfq;

it('defaults a new rfq to the export type without one being given', function () {
    $rfq = Rfq::factory()->create();

    expect($rfq->fresh()->type)->toBe(RfqType::Export);
});

it('persists and casts an explicit domestic manufacturing type', function () {
    $rfq = Rfq::factory()->create(['type' => RfqType::DomesticManufacturing]);

    expect($rfq->fresh()->type)->toBe(RfqType::DomesticManufacturing);
});

it('scopes rfqs by type', function () {
    $export = Rfq::factory()->create(['type' => RfqType::Export]);
    $manufacturing = Rfq::factory()->create(['type' => RfqType::DomesticManufacturing]);

    $found = Rfq::query()->ofType(RfqType::DomesticManufacturing)->pluck('id');

    expect($found)->toContain($manufacturing->id)
        ->and($found)->not->toContain($export->id);
});
