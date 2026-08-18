<?php

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Services\QuoteService;

/*
 * Pure arithmetic and pure state-table checks — no database.
 */

it('multiplies quantity by unit price at two decimal places', function (string $qty, string $price, string $expected) {
    expect(Quote::lineTotal($qty, $price))->toBe($expected);
})->with([
    ['100', '185.00', '18500.00'],
    ['1', '0.01', '0.01'],
    ['3', '0.335', '1.01'],       // rounds half up, not truncated to 1.00
    ['12.5', '19.99', '249.88'],
    ['0.25', '100', '25.00'],
    ['1000000', '999.99', '999990000.00'],
]);

it('never returns a negative or malformed total for zero inputs', function () {
    expect(Quote::lineTotal(0, 500))->toBe('0.00')
        ->and(Quote::lineTotal(10, 0))->toBe('0.00')
        ->and(Quote::lineTotal(null, null))->toBe('0.00');
});

it('declares a state table with exactly one entry per status', function () {
    expect(array_keys(QuoteService::TRANSITIONS))->toEqualCanonicalizing(QuoteStatus::values());
});

it('makes accepted, declined, withdrawn and expired terminal', function () {
    foreach (['accepted', 'declined', 'withdrawn', 'expired'] as $terminal) {
        expect(QuoteService::TRANSITIONS[$terminal])->toBe([]);
        expect(QuoteStatus::from($terminal)->isTerminal())->toBeTrue();
    }
});

it('never lets a draft jump straight to accepted', function () {
    expect(QuoteService::TRANSITIONS['draft'])->not->toContain('accepted')
        ->and(QuoteService::TRANSITIONS['draft'])->not->toContain('declined');
});

it('hides drafts and withdrawn quotes from the buyer-visible set', function () {
    expect(QuoteStatus::buyerVisible())
        ->not->toContain('draft')
        ->not->toContain('withdrawn')
        ->toContain('submitted')
        ->toContain('accepted');
});
