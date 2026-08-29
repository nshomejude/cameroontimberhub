<?php

use App\Models\CompanyDocument;
use App\Models\Document;
use App\Models\DocumentReminderLog;
use App\Models\Species;
use Illuminate\Database\QueryException;

it('records a reminder log against either a CompanyDocument or a polymorphic Document owner', function () {
    $companyDoc = CompanyDocument::factory()->create();
    $log1 = DocumentReminderLog::create([
        'document_owner_type' => CompanyDocument::class,
        'document_owner_id' => $companyDoc->id,
        'threshold' => 30,
        'sent_at' => now(),
    ]);

    $species = Species::factory()->create();
    $document = Document::factory()->create(['owner_type' => Species::class, 'owner_id' => $species->id]);
    $log2 = DocumentReminderLog::create([
        'document_owner_type' => Document::class,
        'document_owner_id' => $document->id,
        'threshold' => 30,
        'sent_at' => now(),
    ]);

    expect($log1->fresh())->not->toBeNull()->and($log2->fresh())->not->toBeNull();
});

it('enforces the unique (document_owner_type, document_owner_id, threshold) constraint', function () {
    $species = Species::factory()->create();
    $document = Document::factory()->create(['owner_type' => Species::class, 'owner_id' => $species->id]);
    DocumentReminderLog::create(['document_owner_type' => Document::class, 'document_owner_id' => $document->id, 'threshold' => 30, 'sent_at' => now()]);

    expect(fn () => DocumentReminderLog::create(['document_owner_type' => Document::class, 'document_owner_id' => $document->id, 'threshold' => 30, 'sent_at' => now()]))
        ->toThrow(QueryException::class);
});
