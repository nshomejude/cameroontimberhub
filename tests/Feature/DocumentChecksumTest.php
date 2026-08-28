<?php

use App\Models\Document;
use App\Models\Species;
use Illuminate\Support\Facades\Storage;

it('computes checksum_sha256 from the actual uploaded file bytes on create', function () {
    Storage::fake('documents');
    $species = Species::factory()->create();
    $path = 'documents/2026/08/evidence-test.pdf';
    Storage::disk('documents')->put($path, 'fixed-file-contents-for-hashing');

    $document = Document::create([
        'owner_type' => Species::class,
        'owner_id' => $species->id,
        'type' => 'source_citation',
        'original_filename' => 'evidence-test.pdf',
        'disk' => 'documents',
        'storage_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => strlen('fixed-file-contents-for-hashing'),
    ]);

    expect($document->checksum_sha256)->toBe(hash('sha256', 'fixed-file-contents-for-hashing'));
});

it('leaves checksum_sha256 null if the file cannot be found on disk at creation time, rather than fabricating a hash', function () {
    Storage::fake('documents');
    $species = Species::factory()->create();

    $document = Document::create([
        'owner_type' => Species::class,
        'owner_id' => $species->id,
        'type' => 'source_citation',
        'original_filename' => 'missing.pdf',
        'disk' => 'documents',
        'storage_path' => 'documents/does-not-exist.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
    ]);

    expect($document->checksum_sha256)->toBeNull();
});

it('never overwrites a checksum the caller supplied explicitly', function () {
    Storage::fake('documents');
    $species = Species::factory()->create();
    Storage::disk('documents')->put('documents/a.pdf', 'aaa');

    $document = Document::create([
        'owner_type' => Species::class,
        'owner_id' => $species->id,
        'type' => 'source_citation',
        'original_filename' => 'a.pdf',
        'disk' => 'documents',
        'storage_path' => 'documents/a.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 3,
        'checksum_sha256' => str_repeat('f', 64),
    ]);

    expect($document->checksum_sha256)->toBe(str_repeat('f', 64));
});

it('keeps the metadata hash chain distinct from the file checksum', function () {
    Storage::fake('documents');
    $species = Species::factory()->create();
    Storage::disk('documents')->put('documents/a.pdf', 'aaa');

    $document = Document::create([
        'owner_type' => Species::class,
        'owner_id' => $species->id,
        'type' => 'source_citation',
        'original_filename' => 'a.pdf',
        'disk' => 'documents',
        'storage_path' => 'documents/a.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 3,
    ]);

    expect($document->checksum_sha256)->toBe(hash('sha256', 'aaa'))
        ->and($document->hash)->not->toBe($document->checksum_sha256);
});
