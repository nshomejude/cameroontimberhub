<?php

use App\Enums\KnowledgeHub;

it('defines every hub from the spec taxonomy with a stable slug', function () {
    $slugs = array_map(fn (KnowledgeHub $h) => $h->value, KnowledgeHub::cases());

    expect($slugs)->toEqual([
        'fundamentals',
        'cameroon-101',
        'products',
        'processing',
        'grading',
        'buying',
        'export',
        'compliance',
        'sustainability',
        'logistics',
        'business',
    ]);
});

it('gives every hub a label and a description with no placeholder text', function () {
    foreach (KnowledgeHub::cases() as $hub) {
        expect($hub->label())->not->toBeEmpty()
            ->and($hub->description())->not->toBeEmpty()
            ->and(strtolower($hub->description()))->not->toContain('lorem')
            ->and(strtolower($hub->description()))->not->toContain('tbd')
            ->and(strtolower($hub->description()))->not->toContain('placeholder');
    }
});

it('resolves a hub from its slug and rejects an unknown one', function () {
    expect(KnowledgeHub::tryFrom('compliance'))->toBe(KnowledgeHub::Compliance)
        ->and(KnowledgeHub::tryFrom('not-a-hub'))->toBeNull();
});
