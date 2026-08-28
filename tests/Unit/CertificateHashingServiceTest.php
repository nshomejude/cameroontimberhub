<?php

use App\Services\CertificateHashingService;

beforeEach(function () {
    $this->service = new CertificateHashingService;
});

it('produces byte-identical canonical JSON regardless of input key order', function () {
    $a = ['origin' => ['country' => 'Cameroon', 'region' => 'East'], 'product' => 'Sapelli'];
    $b = ['product' => 'Sapelli', 'origin' => ['region' => 'East', 'country' => 'Cameroon']];

    expect($this->service->canonicalize($a))->toBe($this->service->canonicalize($b));
});

it('hashes the canonical form with sha256, producing the same hash for reordered-but-equal data', function () {
    $a = ['product' => 'Sapelli', 'quantity' => 120.5];
    $b = ['quantity' => 120.5, 'product' => 'Sapelli'];

    expect($this->service->hash($a))->toBe($this->service->hash($b))
        ->and($this->service->hash($a))->toHaveLength(64);
});

it('produces a different hash when a value actually changes', function () {
    $a = ['quantity' => 120.5];
    $b = ['quantity' => 120.6];

    expect($this->service->hash($a))->not->toBe($this->service->hash($b));
});

it('normalizes floats so 120.50 and 120.5 canonicalize identically, avoiding floating-point ambiguity', function () {
    $a = ['quantity' => 120.50];
    $b = ['quantity' => 120.5];

    expect($this->service->canonicalize($a))->toBe($this->service->canonicalize($b));
});

it('canonicalizes nested arrays and lists deterministically', function () {
    $a = ['evidence' => ['b-doc', 'a-doc'], 'meta' => ['z' => 1, 'a' => 2]];
    $b = ['meta' => ['a' => 2, 'z' => 1], 'evidence' => ['b-doc', 'a-doc']];

    expect($this->service->canonicalize($a))->toBe($this->service->canonicalize($b));
});

it('keeps list order significant, since an ordered evidence list is not a set', function () {
    expect($this->service->hash(['evidence' => ['a', 'b']]))
        ->not->toBe($this->service->hash(['evidence' => ['b', 'a']]));
});

it('normalizes a negative float that rounds to zero without emitting "-0"', function () {
    expect($this->service->canonicalize(['v' => -0.0000001]))->toBe('{"v":"0"}');
});
