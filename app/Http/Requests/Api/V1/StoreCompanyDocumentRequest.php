<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload to upload one compliance document for the caller's company.
 *
 * `type` validates against the real, active `document_types.key` rows (see
 * `Database\Seeders\DocumentTypeSeeder`) rather than a hardcoded list, so a
 * new type added there is immediately accepted here with no code change.
 *
 * The file rules mirror `CompanyDocumentForm`'s Filament upload field exactly
 * (`acceptedFileTypes(['application/pdf','image/jpeg','image/png'])`,
 * `->maxSize(10240)` KB = 10 MB) — the same limits the web upload form
 * enforces, not a guessed API-only number.
 */
class StoreCompanyDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => [
                'required',
                'string',
                Rule::exists(DocumentType::class, 'key')->where('is_active', true),
            ],
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:10240',
            ],
        ];
    }
}
