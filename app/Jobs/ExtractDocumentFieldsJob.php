<?php

namespace App\Jobs;

use App\Models\CompanyDocument;
use App\Models\OrderDocument;
use App\Services\DocumentExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs AI-assisted document extraction (blueprint §35) for a newly uploaded
 * compliance document, off the request cycle. DocumentExtractionService
 * itself catches AI/provider failures and records them on the
 * DocumentExtraction row — this job's own try/catch is a last-resort net so
 * an unexpected exception never turns into a failed-job retry storm against
 * an upload the supplier already successfully made.
 */
class ExtractDocumentFieldsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(private readonly CompanyDocument|OrderDocument $document) {}

    public function handle(DocumentExtractionService $service): void
    {
        try {
            $service->extract($this->document);
        } catch (Throwable $e) {
            Log::error('ExtractDocumentFieldsJob: unhandled failure', [
                'subject_type' => $this->document::class,
                'subject_id' => $this->document->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
