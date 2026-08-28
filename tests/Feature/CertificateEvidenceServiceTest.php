<?php

use App\Models\Certificate;
use App\Models\Document;
use App\Models\Species;
use App\Services\CertificateEvidenceService;
use Illuminate\Support\Facades\Storage;

// Feature, not Unit: every case here touches the database, and tests/Pest.php
// only applies RefreshDatabase to the Feature suite.

beforeEach(function () {
    $this->service = app(CertificateEvidenceService::class);
});

function evidenceDocument(Species $species, string $name, string $contents): Document
{
    Storage::disk('documents')->put("documents/{$name}", $contents);

    return Document::create([
        'owner_type' => Species::class,
        'owner_id' => $species->id,
        'type' => 'evidence',
        'original_filename' => $name,
        'disk' => 'documents',
        'storage_path' => "documents/{$name}",
        'mime_type' => 'application/pdf',
        'file_size' => strlen($contents),
    ]);
}

it('aggregates a set of document checksums into one manifest hash, order-independent', function () {
    Storage::fake('documents');
    $species = Species::factory()->create();

    $docA = evidenceDocument($species, 'a.pdf', 'aaa');
    $docB = evidenceDocument($species, 'b.pdf', 'bbb');

    $forward = $this->service->manifestHash(collect([$docA, $docB]));
    $reversed = $this->service->manifestHash(collect([$docB, $docA]));

    expect($forward)->toBe($reversed)
        ->and($forward)->toHaveLength(64);
});

it('produces a different manifest hash when an evidence file changes', function () {
    Storage::fake('documents');
    $species = Species::factory()->create();

    $original = $this->service->manifestHash(collect([evidenceDocument($species, 'a.pdf', 'aaa')]));
    $altered = $this->service->manifestHash(collect([evidenceDocument($species, 'b.pdf', 'aaa-tampered')]));

    expect($original)->not->toBe($altered);
});

it('refuses to build a manifest that includes a document with no checksum yet', function () {
    $species = Species::factory()->create();
    $unhashed = Document::factory()->for($species, 'owner')->create(['checksum_sha256' => null]);

    expect(fn () => $this->service->manifestHash(collect([$unhashed])))
        ->toThrow(RuntimeException::class);
});

it('binds a manifest hash onto a certificate', function () {
    Storage::fake('documents');
    $species = Species::factory()->create();
    $doc = evidenceDocument($species, 'a.pdf', 'aaa');
    $certificate = Certificate::factory()->create();

    $updated = $this->service->attachEvidence($certificate, collect([$doc]));

    expect($updated->evidence_manifest_hash)->toBe(hash('sha256', $doc->checksum_sha256));
});
