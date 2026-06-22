<?php

namespace App\Filament\Exporter\Resources\CompanyDocuments\Pages;

use App\Enums\DocumentStatus;
use App\Enums\DocumentVisibility;
use App\Filament\Exporter\Resources\CompanyDocuments\CompanyDocumentResource;
use App\Services\DocumentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateCompanyDocument extends CreateRecord
{
    protected static string $resource = CompanyDocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $company = auth()->user()->companies()->firstOrFail();
        $disk = DocumentService::DISK;
        $path = $data['storage_path'];

        $data['company_id'] = $company->getKey();
        $data['disk'] = $disk;
        $data['status'] = DocumentStatus::Pending->value;
        $data['visibility'] = DocumentVisibility::Private->value;
        $data['uploaded_by'] = auth()->id();
        $data['mime_type'] = Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream';
        $data['file_size'] = Storage::disk($disk)->size($path) ?: 0;

        $absolute = Storage::disk($disk)->path($path);
        $data['checksum_sha256'] = is_file($absolute) ? hash_file('sha256', $absolute) : null;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
