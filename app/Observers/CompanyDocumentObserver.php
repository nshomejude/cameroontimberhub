<?php

namespace App\Observers;

use App\Jobs\ExtractDocumentFieldsJob;
use App\Models\CompanyDocument;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues AI-assisted document extraction (blueprint §35) after a compliance
 * document is uploaded. Never allowed to block or fail the upload itself —
 * dispatch is wrapped in try/catch, and the job runs entirely off-request.
 */
class CompanyDocumentObserver
{
    public function created(CompanyDocument $document): void
    {
        try {
            ExtractDocumentFieldsJob::dispatch($document);
        } catch (Throwable $e) {
            Log::error('CompanyDocumentObserver: failed to dispatch extraction job', [
                'document_id' => $document->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
