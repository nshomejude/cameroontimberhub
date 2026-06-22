<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentVisibility;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\DocumentAccessLog;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owns compliance-document storage on the PRIVATE `documents` disk. Files are
 * never web-served directly — access is always a short-lived signed download
 * route, and every access is recorded in document_access_logs.
 */
class DocumentService
{
    public const DISK = 'documents';

    public function store(Company $company, DocumentType $type, UploadedFile $file, ?User $uploader, array $attributes = []): CompanyDocument
    {
        $path = $file->store('companies/'.$company->getKey().'/documents', self::DISK);

        $document = $company->documents()->create(array_merge([
            'document_type_id' => $type->getKey(),
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'disk' => self::DISK,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'checksum_sha256' => @hash_file('sha256', (string) $file->getRealPath()) ?: null,
            'status' => DocumentStatus::Pending,
            'visibility' => DocumentVisibility::Private,
            'uploaded_by' => $uploader?->getKey(),
        ], $attributes));

        activity('compliance')->performedOn($document)->causedBy($uploader)->event('document_uploaded')->log('Document uploaded');

        return $document;
    }

    public function signedDownloadUrl(CompanyDocument $document, ?User $user): string
    {
        $this->logAccess($document, $user, 'signed_url_issued');

        return URL::temporarySignedRoute(
            'documents.download',
            now()->addMinutes((int) config('compliance.signed_url_ttl', 5)),
            ['document' => $document->getKey()],
        );
    }

    public function download(CompanyDocument $document, ?User $user): StreamedResponse
    {
        $this->logAccess($document, $user, 'download');

        return Storage::disk($document->disk)->download($document->storage_path, $document->original_filename);
    }

    public function logAccess(CompanyDocument $document, ?User $user, string $action): void
    {
        $request = request();

        DocumentAccessLog::create([
            'company_document_id' => $document->getKey(),
            'user_id' => $user?->getKey(),
            'action' => $action,
            'ip_address' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
