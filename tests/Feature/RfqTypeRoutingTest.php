<?php

use App\Enums\RfqStatus;
use App\Enums\RfqType;
use App\Filament\Resources\Rfqs\Tables\RfqsTable;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Rfq;
use App\Models\Species;
use App\Services\RfqMatchingService;
use Illuminate\Support\Str;

function typeRoutingRfq(RfqType $type, ?Species $species = null): Rfq
{
    $rfq = Rfq::create([
        'reference_code' => 'RFQ-2026-'.Str::upper(Str::random(5)),
        'buyer_name' => 'Buyer',
        'buyer_email' => 'buyer@acme.test',
        'status' => RfqStatus::Approved->value,
        'visibility' => 'public',
        'type' => $type->value,
    ]);

    if ($species) {
        $rfq->items()->create(['species_id' => $species->id, 'form' => 'sawn', 'quantity' => 10, 'unit' => 'm3']);
    }

    return $rfq;
}

function typeRoutingCompany(string $type, ?Species $species = null, string $name = ''): Company
{
    $plan = Plan::factory()->create(['features' => ['leads_receive' => true]]);
    $company = Company::factory()->publiclyVisible()->create(array_filter([
        'plan_id' => $plan->id,
        'type' => $type,
        'legal_name' => $name ?: null,
    ]));

    if ($species) {
        $company->species()->attach($species->id, ['form' => 'sawn']);
    }

    return $company;
}

function suggestedIds(Rfq $rfq): array
{
    return app(RfqMatchingService::class)->suggestSuppliers($rfq->load('items.species'))
        ->pluck('company.id')->all();
}

it('suggests manufacturers, artisans and processors for a domestic manufacturing RFQ', function () {
    $species = Species::factory()->create();
    $manufacturer = typeRoutingCompany('manufacturer', $species);
    $artisan = typeRoutingCompany('artisan', $species);
    $processor = typeRoutingCompany('processor', $species);
    $supplier = typeRoutingCompany('supplier', $species);
    $logistics = typeRoutingCompany('logistics', $species);

    $ids = suggestedIds(typeRoutingRfq(RfqType::DomesticManufacturing, $species));

    expect($ids)->toContain($manufacturer->id, $artisan->id, $processor->id)
        ->not->toContain($supplier->id)
        ->not->toContain($logistics->id);
});

it('suggests logistics companies for a transport RFQ without requiring a species match', function () {
    $species = Species::factory()->create();
    $logistics = typeRoutingCompany('logistics'); // handles no species at all
    $supplier = typeRoutingCompany('supplier', $species);

    $ids = suggestedIds(typeRoutingRfq(RfqType::Transport, $species));

    expect($ids)->toBe([$logistics->id]);
});

it('keeps the export RFQ candidate set species-led and type-agnostic', function () {
    $species = Species::factory()->create();
    $supplier = typeRoutingCompany('supplier', $species);
    $processor = typeRoutingCompany('processor', $species);
    $unrelated = typeRoutingCompany('supplier');

    $ids = suggestedIds(typeRoutingRfq(RfqType::Export, $species));

    expect($ids)->toContain($supplier->id, $processor->id)->not->toContain($unrelated->id);
});

it('groups suitable companies first in the admin routing selector', function () {
    $logistics = typeRoutingCompany('logistics', name: 'Zed Haulage');
    $supplier = typeRoutingCompany('supplier', name: 'Alpha Timber');

    $options = RfqsTable::routingOptions(typeRoutingRfq(RfqType::Transport));

    expect(array_keys($options))->toBe(['Suggested for Transport', 'Other companies'])
        ->and(array_keys($options['Suggested for Transport']))->toBe([$logistics->id])
        ->and($options['Other companies'])->toHaveKey($supplier->id);

    $exportOptions = RfqsTable::routingOptions(typeRoutingRfq(RfqType::Export));
    expect($exportOptions)->toHaveKeys([$logistics->id, $supplier->id]);
});
