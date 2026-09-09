<?php

namespace App\Services;

use App\Enums\DocumentExtractionStatus;
use App\Exceptions\AiProviderNotConfiguredException;
use App\Models\CompanyDocument;
use App\Models\DocumentExtraction;
use App\Models\AiSetting;
use App\Models\OrderDocument;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * AI-assisted document extraction (blueprint §35).
 *
 * REVIEW AID ONLY — the fields this stores on DocumentExtraction are for a
 * human (admin/compliance officer) to look at and act on themselves. Nothing
 * here writes to CompanyDocument/OrderDocument's own status/verification
 * columns, and no other service may treat extracted_fields as ground truth.
 *
 * Text extraction limitation: there is no PDF-text or OCR library in this
 * codebase (checked composer.json — none present) and adding one is outside
 * this change's scope. Plain-text files are read directly. PDFs get a
 * best-effort extraction of literal text runs from their content streams
 * (works for simple, non-compressed PDFs; will miss text in PDFs that use
 * compressed streams or embedded fonts with custom encodings). Images and
 * anything else fall back to passing filename/mime/size context only — the
 * AI provider is still called (it may glean something from the schema
 * description alone) but is unlikely to find real evidence for most fields,
 * which is the correct, safe outcome given extractStructured()'s contract
 * of never fabricating values.
 */
class DocumentExtractionService
{
    public function __construct(private readonly AiGateway $gateway) {}

    public function extract(CompanyDocument|OrderDocument $document): DocumentExtraction
    {
        $extraction = DocumentExtraction::create([
            'subject_type' => $document::class,
            'subject_id' => $document->getKey(),
            'extraction_status' => DocumentExtractionStatus::Pending,
        ]);

        try {
            $schema = (array) config('document_extraction.fields', []);
            $text = $this->extractText($document);

            $fields = $this->gateway->driver()->extractStructured($text, $schema);
            $activeSetting = AiSetting::query()->where('is_active', true)->first();

            $extraction->update([
                'extraction_status' => empty($fields)
                    ? DocumentExtractionStatus::NeedsReview
                    : DocumentExtractionStatus::Completed,
                'extracted_fields' => $fields,
                'ai_provider' => $activeSetting?->provider?->value ?? \App\Enums\AiProvider::Claude->value,
                'model_used' => $activeSetting?->model ?? \App\Enums\AiProvider::Claude->defaultModel(),
                'extracted_at' => now(),
            ]);
        } catch (AiProviderNotConfiguredException $e) {
            $extraction->update([
                'extraction_status' => DocumentExtractionStatus::Failed,
                'error_note' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            Log::error('DocumentExtractionService: extraction failed', [
                'subject_type' => $document::class,
                'subject_id' => $document->getKey(),
                'error' => $e->getMessage(),
            ]);

            $extraction->update([
                'extraction_status' => DocumentExtractionStatus::Failed,
                'error_note' => 'Extraction failed: '.$e->getMessage(),
            ]);
        }

        return $extraction->fresh();
    }

    /** Best-effort text for the AI prompt — see class doc block for the PDF/image limitation. */
    private function extractText(CompanyDocument|OrderDocument $document): string
    {
        $maxLength = (int) config('document_extraction.max_text_length', 12000);
        $disk = $document->disk;
        $path = $document->storage_path;
        $mime = (string) $document->mime_type;
        $filename = (string) $document->original_filename;

        $contents = null;

        try {
            if (Storage::disk($disk)->exists($path)) {
                if (str_starts_with($mime, 'text/')) {
                    $contents = Storage::disk($disk)->get($path);
                } elseif ($mime === 'application/pdf') {
                    $contents = $this->bestEffortPdfText(Storage::disk($disk)->get($path));
                }
            }
        } catch (Throwable $e) {
            Log::error('DocumentExtractionService: could not read document file', [
                'subject_type' => $document::class,
                'subject_id' => $document->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        if (blank($contents)) {
            // Image or unreadable/unsupported file: no OCR available, so give
            // the model only descriptive context. It is expected to find
            // little or no evidence for most fields from this alone.
            $contents = "[No extractable text — file metadata only]\nFilename: {$filename}\nMIME type: {$mime}";
        }

        return mb_substr((string) $contents, 0, $maxLength);
    }

    /** Very small, dependency-free best-effort PDF text scrape. Not a substitute for a real PDF parser. */
    private function bestEffortPdfText(string $raw): string
    {
        $matches = [];
        preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/', $raw, $matches);

        $text = collect($matches[0] ?? [])
            ->map(fn (string $chunk) => trim($chunk, '()'))
            ->map(fn (string $chunk) => stripcslashes($chunk))
            ->implode(' ');

        return trim($text);
    }
}
