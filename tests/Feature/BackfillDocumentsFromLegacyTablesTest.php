<?php

use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Document;

it('backfills CompanyDocument rows into documents, preserving upload order for a correct hash chain', function () {
    $company = Company::factory()->create();
    $doc1 = CompanyDocument::factory()->create(['company_id' => $company->id, 'created_at' => now()->subDays(2)]);
    $doc2 = CompanyDocument::factory()->create(['company_id' => $company->id, 'created_at' => now()->subDay()]);
    $doc3 = CompanyDocument::factory()->create(['company_id' => $company->id, 'created_at' => now()]);

    $this->artisan('documents:backfill-from-legacy')->assertSuccessful();

    $chain = Document::where('owner_type', Company::class)->where('owner_id', $company->id)->orderBy('id')->get();
    expect($chain)->toHaveCount(3)
        ->and($chain[0]->prev_hash)->toBeNull()
        ->and($chain[1]->prev_hash)->toBe($chain[0]->hash)
        ->and($chain[2]->prev_hash)->toBe($chain[1]->hash)
        ->and($chain[0]->document_type_id)->toBe($doc1->document_type_id)
        ->and($chain[0]->visibility->value)->toBe($doc1->visibility->value);
});

it('is idempotent -- running it twice does not create duplicate Document rows', function () {
    $company = Company::factory()->create();
    CompanyDocument::factory()->create(['company_id' => $company->id]);

    $this->artisan('documents:backfill-from-legacy')->assertSuccessful();
    $firstCount = Document::count();
    $this->artisan('documents:backfill-from-legacy')->assertSuccessful();

    expect(Document::count())->toBe($firstCount);
});
