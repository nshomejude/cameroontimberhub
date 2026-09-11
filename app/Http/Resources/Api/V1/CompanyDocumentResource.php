<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CompanyDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One compliance document on the caller's own company — the API counterpart
 * of the row `CompanyDocumentsTable` renders in the Filament exporter panel.
 *
 * `download_url` points at this same v1 API (not the web signed-URL route):
 * `documents.download` is gated by the web `auth` (session) middleware, which
 * a Sanctum-token-only mobile client never has, so a signed link to it would
 * 404/redirect-to-login for the app. See `CompanyDocumentController::download()`.
 *
 * @mixin CompanyDocument
 */
class CompanyDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->documentType?->key,
            'label' => $this->documentType?->name,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'issue_date' => $this->issue_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'is_expired' => $this->isExpired(),
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'download_url' => route('api.v1.company.documents.download', ['document' => $this->id]),
        ];
    }
}
