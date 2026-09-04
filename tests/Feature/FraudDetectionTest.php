<?php

use App\Enums\FraudSignalType;
use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\FraudSignal;
use App\Models\Product;
use App\Models\Species;
use App\Models\User;
use App\Services\FraudDetectionService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->fraud = app(FraudDetectionService::class);
});

/* --------------------------------------------------------------- duplicate companies */

it('detects a duplicate company by matching registration number', function () {
    $existing = Company::factory()->create(['registration_number' => 'RC/DLA/2024/B/1234']);
    $candidate = Company::factory()->create(['registration_number' => 'RC/DLA/2024/B/1234']);

    $matches = $this->fraud->detectDuplicateCompanies($candidate);

    expect($matches)->not->toBeEmpty()
        ->and(collect($matches)->pluck('company_id'))->toContain($existing->id)
        ->and(FraudSignal::query()->where('subject_type', Company::class)->where('subject_id', $candidate->id)
            ->where('signal_type', FraudSignalType::DuplicateCompany->value)->exists())->toBeTrue();
});

it('detects a duplicate company by matching primary contact email', function () {
    $existing = Company::factory()->create(['email' => 'contact@sameexporter.cm']);
    $candidate = Company::factory()->create(['email' => 'contact@sameexporter.cm']);

    $matches = $this->fraud->detectDuplicateCompanies($candidate);

    expect(collect($matches)->pluck('company_id'))->toContain($existing->id);
});

it('finds no duplicate for a genuinely distinct company', function () {
    Company::factory()->create(['registration_number' => 'RC/DLA/2024/B/0001', 'email' => 'one@example.cm']);
    $candidate = Company::factory()->create(['registration_number' => 'RC/YAO/2024/B/9999', 'email' => 'two@example.cm']);

    $matches = $this->fraud->detectDuplicateCompanies($candidate);

    expect($matches)->toBeEmpty()
        ->and(FraudSignal::query()->where('subject_id', $candidate->id)->where('subject_type', Company::class)->exists())->toBeFalse();
});

/* --------------------------------------------------------------- pricing anomaly */

it('flags a pricing anomaly when a product price deviates far from the species median', function () {
    $species = Species::factory()->create();

    Product::factory()->count(3)->create([
        'species_id' => $species->id,
        'status' => ProductStatus::Active,
        'price_amount' => 100000,
        'price_currency' => 'XAF',
    ]);

    $outlier = Product::factory()->create([
        'species_id' => $species->id,
        'status' => ProductStatus::Active,
        'price_amount' => 900000, // 9x the 100000 median
        'price_currency' => 'XAF',
    ]);

    $result = $this->fraud->detectPricingAnomaly($outlier);

    expect($result)->not->toBeNull()
        ->and($result['ratio'])->toBeGreaterThan(3.0)
        ->and(FraudSignal::query()->where('subject_type', Product::class)->where('subject_id', $outlier->id)
            ->where('signal_type', FraudSignalType::PricingAnomaly->value)->exists())->toBeTrue();
});

it('does not flag a pricing anomaly for a price within normal range', function () {
    $species = Species::factory()->create();

    Product::factory()->count(3)->create([
        'species_id' => $species->id,
        'status' => ProductStatus::Active,
        'price_amount' => 100000,
        'price_currency' => 'XAF',
    ]);

    $normal = Product::factory()->create([
        'species_id' => $species->id,
        'status' => ProductStatus::Active,
        'price_amount' => 110000,
        'price_currency' => 'XAF',
    ]);

    $result = $this->fraud->detectPricingAnomaly($normal);

    expect($result)->toBeNull();
});

it('does not flag a pricing anomaly when there are fewer than 3 comparable products', function () {
    $species = Species::factory()->create();

    Product::factory()->count(1)->create([
        'species_id' => $species->id,
        'status' => ProductStatus::Active,
        'price_amount' => 100000,
        'price_currency' => 'XAF',
    ]);

    $outlier = Product::factory()->create([
        'species_id' => $species->id,
        'status' => ProductStatus::Active,
        'price_amount' => 900000,
        'price_currency' => 'XAF',
    ]);

    $result = $this->fraud->detectPricingAnomaly($outlier);

    expect($result)->toBeNull();
});

/* --------------------------------------------------------------- login anomaly */

it('flags a login anomaly for a genuinely new IP on a user with established login history', function () {
    $user = User::factory()->create([
        'last_login_at' => now()->subDays(3),
        'known_login_ips' => ['41.202.10.5'],
    ]);

    $result = $this->fraud->detectLoginAnomaly($user, '105.101.4.99', 'Mozilla/5.0');

    expect($result)->not->toBeNull()
        ->and(FraudSignal::query()->where('subject_type', User::class)->where('subject_id', $user->id)
            ->where('signal_type', FraudSignalType::LoginAnomaly->value)->exists())->toBeTrue();
});

it('does not flag a login anomaly for a known IP', function () {
    $user = User::factory()->create([
        'last_login_at' => now()->subDays(3),
        'known_login_ips' => ['41.202.10.5'],
    ]);

    $result = $this->fraud->detectLoginAnomaly($user, '41.202.10.5', 'Mozilla/5.0');

    expect($result)->toBeNull();
});

it('does not flag a login anomaly for a user with no established login history', function () {
    $user = User::factory()->create([
        'last_login_at' => null,
        'known_login_ips' => null,
    ]);

    $result = $this->fraud->detectLoginAnomaly($user, '105.101.4.99', 'Mozilla/5.0');

    expect($result)->toBeNull();
});

it('records login history and detects an anomaly end-to-end via the Login event listener', function () {
    $user = User::factory()->create([
        'last_login_at' => now()->subDays(5),
        'known_login_ips' => ['10.0.0.1'],
    ]);

    request()->server->set('REMOTE_ADDR', '203.0.113.7');

    event(new Login('web', $user, false));

    $user->refresh();

    expect($user->last_login_ip)->toBe('203.0.113.7')
        ->and($user->known_login_ips)->toContain('203.0.113.7')
        ->and(FraudSignal::query()->where('subject_type', User::class)->where('subject_id', $user->id)
            ->where('signal_type', FraudSignalType::LoginAnomaly->value)->exists())->toBeTrue();
});

/* --------------------------------------------------------------- failure injection */

it('still saves the product even when fraud detection throws internally', function () {
    Log::shouldReceive('error')->atLeast()->once();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    // Simulate an internal failure inside FraudDetectionService without
    // touching FraudSignal.php directly: a temporary `creating` listener
    // that throws before any SQL runs (mirrors ShipmentLotLinkingTest's
    // failure-injection pattern -- avoids a real Postgres error aborting
    // Pest's per-test transaction).
    FraudSignal::creating(function () {
        throw new RuntimeException('Simulated fraud-detection failure for test.');
    });

    try {
        $species = Species::factory()->create();

        Product::factory()->count(3)->create([
            'species_id' => $species->id,
            'status' => ProductStatus::Active,
            'price_amount' => 100000,
            'price_currency' => 'XAF',
        ]);

        // This save triggers ProductObserver, which calls
        // FraudDetectionService::detectPricingAnomaly() -> FraudSignal::create(),
        // which now throws. The product save itself must still succeed.
        $product = Product::factory()->create([
            'species_id' => $species->id,
            'status' => ProductStatus::Active,
            'price_amount' => 900000,
            'price_currency' => 'XAF',
        ]);
    } finally {
        Illuminate\Support\Facades\Event::forget('eloquent.creating: '.FraudSignal::class);
    }

    expect($product->exists)->toBeTrue()
        ->and($product->wasRecentlyCreated)->toBeTrue();
});

it('still logs the user in even when login-anomaly detection throws internally', function () {
    Log::shouldReceive('error')->atLeast()->once();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    $user = User::factory()->create([
        'last_login_at' => now()->subDays(5),
        'known_login_ips' => ['10.0.0.1'],
    ]);

    FraudSignal::creating(function () {
        throw new RuntimeException('Simulated fraud-detection failure for test.');
    });

    try {
        request()->server->set('REMOTE_ADDR', '203.0.113.9');

        // The listener is registered on Illuminate\Auth\Events\Login and is
        // fully try/catch-wrapped: dispatching the event must not throw even
        // though the fraud signal write inside it fails.
        event(new Login('web', $user, false));
    } finally {
        Illuminate\Support\Facades\Event::forget('eloquent.creating: '.FraudSignal::class);
    }

    // Reaching this line without an exception is the assertion: the Login
    // event (standing in for the real login completing) was not disrupted.
    expect(true)->toBeTrue();
});
