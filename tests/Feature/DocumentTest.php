<?php

use App\Enums\DocumentVerificationStatus;
use App\Models\Document;
use App\Models\Species;

it('attaches a polymorphic document to an owning model', function () {
    $species = Species::factory()->create();

    $document = Document::create([
        'owner_type' => Species::class,
        'owner_id' => $species->id,
        'type' => 'source_citation',
        'original_filename' => 'cites-appendix-ii-notice.pdf',
        'disk' => 'documents',
        'storage_path' => 'documents/2026/08/test-'.uniqid().'.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 24576,
        'issuer' => 'CITES Secretariat',
    ]);

    expect($document->owner)->toBeInstanceOf(Species::class)
        ->and($document->owner->is($species))->toBeTrue()
        ->and($species->fresh()->documents)->toHaveCount(1)
        ->and($species->fresh()->documents->first()->is($document))->toBeTrue();
});

it('defaults verification_status to unverified, never a fabricated verified state', function () {
    $species = Species::factory()->create();

    $document = Document::factory()->for($species, 'owner')->create();

    expect($document->verification_status)->toBe(DocumentVerificationStatus::Unverified);
});

it('computes hash and prev_hash to chain documents for the same owner in upload order', function () {
    $species = Species::factory()->create();

    $first = Document::factory()->for($species, 'owner')->create(['original_filename' => 'a.pdf']);
    $second = Document::factory()->for($species, 'owner')->create(['original_filename' => 'b.pdf']);

    expect($first->hash)->not->toBeNull()
        ->and($first->prev_hash)->toBeNull()
        ->and($second->prev_hash)->toBe($first->hash)
        ->and($second->hash)->not->toBe($first->hash);
});

it('flags an expired document without deleting it', function () {
    $expired = Document::factory()->create(['expires_at' => now()->subDay()]);
    $valid = Document::factory()->create(['expires_at' => now()->addYear()]);
    $undated = Document::factory()->create(['expires_at' => null]);

    expect($expired->isExpired())->toBeTrue()
        ->and($valid->isExpired())->toBeFalse()
        ->and($undated->isExpired())->toBeFalse();
});

it('scopes to a given owner type and id', function () {
    $speciesA = Species::factory()->create();
    $speciesB = Species::factory()->create();
    Document::factory()->for($speciesA, 'owner')->create();
    Document::factory()->for($speciesB, 'owner')->create();

    $found = Document::query()->forOwner($speciesA)->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->owner_id)->toBe($speciesA->id);
});
