<?php

use App\Enums\RfqType;
use App\Models\Rfq;
use App\Models\Species;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->species = Species::factory()->create(['common_name' => 'Iroko', 'slug' => 'iroko-manu', 'is_published' => true]);
});

it('renders the manufacturing rfq entry point on the same wizard', function () {
    $this->get('/request-quote/manufacturing')->assertOk();
});

it('tags a multi-line manufacturing rfq submission with the domestic type', function () {
    $steps = [
        'details' => ['title' => 'Doors and windows for a housing project'],
        'products' => ['items' => [
            [
                'species_text' => 'Doors',
                'form' => 'other',
                'quantity' => '500',
                'unit' => 'pcs',
            ],
            [
                'species_text' => 'Windows',
                'form' => 'other',
                'quantity' => '200',
                'unit' => 'pcs',
            ],
        ]],
        'delivery' => [
            'destination_country_code' => 'CM',
            'notes' => 'Local procurement for a housing construction project in Douala.',
        ],
        'contact' => [
            'buyer_name' => 'Local Builder',
            'buyer_email' => 'builder@local.test',
            'buyer_country_code' => 'CM',
            'consent' => '1',
        ],
    ];

    // The manufacturing entry point must seed the session's type before any
    // step is banked.
    $this->get('/request-quote/manufacturing')->assertOk();

    foreach ($steps as $slug => $payload) {
        $this->post("/request-quote/step/{$slug}", $payload)->assertRedirect();
    }

    $this->post('/request-quote')->assertRedirect('/request-quote/thanks');

    expect(Rfq::count())->toBe(1);

    $rfq = Rfq::with('items')->first();

    expect($rfq->type)->toBe(RfqType::DomesticManufacturing)
        ->and($rfq->items)->toHaveCount(2)
        ->and($rfq->items->pluck('species_text')->all())->toBe(['Doors', 'Windows']);
});

it('still tags the plain export wizard submission with the export type', function () {
    $steps = [
        'details' => ['title' => 'Sawn Iroko for export'],
        'products' => ['items' => [[
            'species_id' => $this->species->id,
            'form' => 'sawn',
            'quantity' => '30',
            'unit' => 'm3',
        ]]],
        'delivery' => [
            'destination_country_code' => 'NL',
            'notes' => 'Kiln dried to 12 percent, FAS grade, please quote CIF Rotterdam.',
        ],
        'contact' => [
            'buyer_name' => 'Bob Buyer',
            'buyer_email' => 'bob@acme.test',
            'buyer_country_code' => 'NL',
            'consent' => '1',
        ],
    ];

    $this->get('/request-quote')->assertOk();

    foreach ($steps as $slug => $payload) {
        $this->post("/request-quote/step/{$slug}", $payload)->assertRedirect();
    }

    $this->post('/request-quote')->assertRedirect('/request-quote/thanks');

    expect(Rfq::first()->type)->toBe(RfqType::Export);
});
