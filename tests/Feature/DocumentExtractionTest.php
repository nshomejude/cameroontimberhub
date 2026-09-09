<?php

use App\Contracts\AiProviderContract;
use App\Enums\DocumentExtractionStatus;
use App\Exceptions\AiProviderNotConfiguredException;
use App\Jobs\ExtractDocumentFieldsJob;
use App\Models\CompanyDocument;
use App\Models\DocumentExtraction;
use App\Services\DocumentExtractionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

it('dispatches an extraction job when a company document is created', function () {
    Queue::fake();

    $document = CompanyDocument::factory()->create();

    Queue::assertPushed(ExtractDocumentFieldsJob::class);
});

it('extracts and stores structured fields when the AI provider is configured', function () {
    $fake = new class implements AiProviderContract
    {
        public function complete(string $systemPrompt, string $userPrompt, array $options = []): string
        {
            return '{}';
        }

        public function extractStructured(string $documentText, array $schema): array
        {
            return [
                'document_number' => 'FLEGT-2026-000123',
                'issuing_authority' => 'MINFOF',
            ];
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };

    $this->app->bind(AiProviderContract::class, fn () => $fake);
    $this->app->bind(\App\Services\Ai\ClaudeProvider::class, fn () => $fake);

    $document = CompanyDocument::factory()->create();

    $extraction = app(DocumentExtractionService::class)->extract($document);

    expect($extraction)->toBeInstanceOf(DocumentExtraction::class)
        ->and($extraction->extraction_status)->toBe(DocumentExtractionStatus::Completed)
        ->and($extraction->extracted_fields)->toBe([
            'document_number' => 'FLEGT-2026-000123',
            'issuing_authority' => 'MINFOF',
        ])
        ->and($extraction->extracted_at)->not->toBeNull()
        ->and($extraction->subject_type)->toBe(CompanyDocument::class)
        ->and($extraction->subject_id)->toBe($document->getKey());
});

it('records a failed extraction status when the AI provider is not configured', function () {
    $unconfigured = new class implements AiProviderContract
    {
        public function complete(string $systemPrompt, string $userPrompt, array $options = []): string
        {
            throw new AiProviderNotConfiguredException('claude');
        }

        public function extractStructured(string $documentText, array $schema): array
        {
            throw new AiProviderNotConfiguredException('claude');
        }

        public function isConfigured(): bool
        {
            return false;
        }
    };

    $this->app->bind(\App\Services\Ai\ClaudeProvider::class, fn () => $unconfigured);

    $document = CompanyDocument::factory()->create();

    $extraction = app(DocumentExtractionService::class)->extract($document);

    expect($extraction->extraction_status)->toBe(DocumentExtractionStatus::Failed)
        ->and($extraction->error_note)->toContain('not configured');
});

it('still saves the document even when extraction throws internally', function () {
    Log::shouldReceive('error')->atLeast()->once();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    // Simulate an internal failure inside the extraction pipeline without
    // touching DocumentExtraction.php: a temporary `creating` listener that
    // throws before any SQL runs, mirroring
    // tests/Feature/ShipmentLotLinkingTest.php's failure-injection pattern.
    DocumentExtraction::creating(function () {
        throw new \RuntimeException('Simulated extraction-row failure for test.');
    });

    try {
        $document = CompanyDocument::factory()->create();

        // The extraction job runs synchronously here (default queue driver in
        // tests) via the observer's dispatch(); its own try/catch (and the
        // job's) must swallow the failure so document creation itself never
        // fails or throws.
    } finally {
        \Illuminate\Support\Facades\Event::forget('eloquent.creating: '.DocumentExtraction::class);
    }

    expect($document->exists)->toBeTrue()
        ->and($document->wasRecentlyCreated)->toBeTrue();
});
