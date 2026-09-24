<?php

use App\Services\BarcodeService;

it('renders a certificate/lot/waybill/product identifier as a valid inline SVG barcode data URI', function () {
    $service = new BarcodeService;

    $dataUri = $service->svgDataUri('CTH-CERT-000123');

    expect($dataUri)->toStartWith('data:image/svg+xml;base64,');

    $encoded = Str::after($dataUri, 'data:image/svg+xml;base64,');
    $svg = base64_decode($encoded, true);

    expect($svg)->not->toBeFalse()
        ->and($svg)->toContain('<svg')
        ->and($svg)->toContain('</svg>');
});

it('encodes different identifiers into different barcode output', function () {
    $service = new BarcodeService;

    expect($service->svgDataUri('CTH-CERT-000123'))
        ->not->toBe($service->svgDataUri('CTH-LOT-000456'));
});
