<?php

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Services\OrderService;

/**
 * Pure structural checks on the state machine and its enums — no database.
 * The behavioural half (timestamps, logging, guards) lives in OrderTest.
 */
it('declares a transition table covering every status exactly once', function () {
    expect(array_keys(OrderService::TRANSITIONS))
        ->toEqualCanonicalizing(OrderStatus::values());
});

it('only ever transitions to a real status', function () {
    foreach (OrderService::TRANSITIONS as $from => $targets) {
        expect(OrderStatus::tryFrom($from))->not->toBeNull();

        foreach ($targets as $to) {
            expect(OrderStatus::tryFrom($to))->not->toBeNull("'{$to}' is not an OrderStatus");
            expect($to)->not->toBe($from, "{$from} must not transition to itself");
        }
    }
});

it('makes completed and cancelled dead ends', function () {
    expect(OrderService::TRANSITIONS['completed'])->toBe([])
        ->and(OrderService::TRANSITIONS['cancelled'])->toBe([])
        ->and(OrderStatus::Completed->isTerminal())->toBeTrue()
        ->and(OrderStatus::Cancelled->isTerminal())->toBeTrue();
});

it('never allows a settled order to be cancelled', function () {
    foreach (['completed', 'cancelled'] as $terminal) {
        expect(OrderService::TRANSITIONS[$terminal])->not->toContain('cancelled');
    }
});

it('cannot reach shipped without passing through confirmed', function () {
    // The only routes into `shipped` start at a confirmed order.
    $sources = collect(OrderService::TRANSITIONS)
        ->filter(fn (array $targets) => in_array('shipped', $targets, true))
        ->keys();

    expect($sources->all())->toEqualCanonicalizing(['confirmed', 'in_production'])
        ->and($sources)->not->toContain('awarded');
});

it('reaches every status from the initial one', function () {
    $seen = ['awarded'];
    $frontier = ['awarded'];

    while ($frontier) {
        foreach (array_splice($frontier, 0) as $status) {
            foreach (OrderService::TRANSITIONS[$status] as $next) {
                if (! in_array($next, $seen, true)) {
                    $seen[] = $next;
                    $frontier[] = $next;
                }
            }
        }
    }

    expect($seen)->toEqualCanonicalizing(OrderStatus::values());
});

it('gives every status a label, a colour and a literal description', function () {
    foreach (OrderStatus::cases() as $status) {
        expect($status->label())->not->toBeEmpty()
            ->and($status->color())->not->toBeEmpty()
            ->and($status->description())->not->toBeEmpty();
    }
});

it('never claims money moved in any status description', function () {
    // The platform takes no payments, so no lifecycle copy may imply one.
    foreach (OrderStatus::cases() as $status) {
        expect(strtolower($status->description()))
            ->not->toContain('paid')
            ->not->toContain('payment received')
            ->not->toContain('charged');
    }
});

it('orders the buyer-facing milestones without the cancelled branch', function () {
    expect(OrderStatus::milestones())->toBe([
        OrderStatus::Awarded,
        OrderStatus::Confirmed,
        OrderStatus::InProduction,
        OrderStatus::Shipped,
        OrderStatus::Delivered,
        OrderStatus::Completed,
    ]);
});

it('defaults settlement to unpaid and labels it honestly', function () {
    expect(OrderPaymentStatus::Unpaid->label())->toBe('No payment recorded')
        ->and(OrderPaymentStatus::options())->toHaveCount(3);
});
