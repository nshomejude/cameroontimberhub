<?php

use App\Enums\CreditSource;
use App\Models\Company;
use App\Models\Credit;
use App\Services\Billing\CreditLedger;

it('sums the ledger as the balance', function () {
    $company = Company::factory()->create();
    $ledger = app(CreditLedger::class);

    $ledger->grant($company, '10000', 'XAF', 'goodwill', CreditSource::Goodwill);
    $ledger->grant($company, '5000', 'XAF', 'referral', CreditSource::Referral);

    expect($ledger->balance($company, 'XAF'))->toBe('15000.00');
});

it('throws when consuming beyond the balance', function () {
    $company = Company::factory()->create();
    $ledger = app(CreditLedger::class);

    $ledger->grant($company, '1000', 'XAF', 'goodwill', CreditSource::Goodwill);

    $ledger->consume($company, '2000', 'XAF', 'overspend');
})->throws(RuntimeException::class);

it('consumes a credit and lowers the balance', function () {
    $company = Company::factory()->create();
    $ledger = app(CreditLedger::class);

    $ledger->grant($company, '1000', 'XAF', 'goodwill', CreditSource::Goodwill);
    $ledger->consume($company, '400', 'XAF', 'used on invoice');

    expect($ledger->balance($company, 'XAF'))->toBe('600.00')
        ->and(Credit::count())->toBe(2);
});

it('never mixes balances across currencies', function () {
    $company = Company::factory()->create();
    $ledger = app(CreditLedger::class);

    $ledger->grant($company, '10000', 'XAF', 'goodwill', CreditSource::Goodwill);
    $ledger->grant($company, '50', 'USD', 'goodwill', CreditSource::Goodwill);

    expect($ledger->balance($company, 'XAF'))->toBe('10000.00')
        ->and($ledger->balance($company, 'USD'))->toBe('50.00');
});

it('excludes expired credits from the balance', function () {
    $company = Company::factory()->create();
    $ledger = app(CreditLedger::class);

    $ledger->grant($company, '10000', 'XAF', 'expiring', CreditSource::Goodwill, null, now()->subDay());
    $ledger->grant($company, '3000', 'XAF', 'still valid', CreditSource::Goodwill);

    expect($ledger->balance($company, 'XAF'))->toBe('3000.00');
});
