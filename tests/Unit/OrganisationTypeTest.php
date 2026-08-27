<?php

use App\Enums\OrganisationType;

it('has exactly the 10 organisation types from the build brief', function () {
    $values = array_column(OrganisationType::cases(), 'value');

    expect($values)->toEqualCanonicalizing([
        'supplier',
        'processor',
        'manufacturer',
        'artisan',
        'buyer',
        'retailer',
        'logistics',
        'carbon_developer',
        'financier',
        'training_provider',
    ]);
});

it('gives every case a label and a color', function () {
    foreach (OrganisationType::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty()
            ->and($case->color())->toBeString()->not->toBeEmpty();
    }
});

it('lists options keyed by value', function () {
    expect(OrganisationType::options())->toHaveKey('processor', 'Processor');
});
