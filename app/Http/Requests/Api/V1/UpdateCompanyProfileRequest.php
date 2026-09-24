<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A supplier's own company-profile edit over the API — the `PATCH`
 * counterpart of {@see \App\Http\Controllers\Api\V1\CompanyProfileController}.
 *
 * Mirrors {@see \App\Filament\Exporter\Resources\Companies\Schemas\CompanyForm}
 * field-for-field. Every scalar field is `sometimes` so a partial PATCH only
 * validates/updates the keys actually present, but a field that IS present is
 * validated with the exact same rule the exporter-panel form applies
 * (`legal_name` required/max 255, `description` required/min 50, `region`
 * required/max 120, `country_code` max 2, etc.).
 *
 * `species`, `export_markets`, `contacts` and `gallery` are collection
 * fields that, like a Filament Repeater bound via `->relationship()`,
 * fully replace their existing rows on submit rather than merging — so
 * each is validated as a whole array when present (not `sometimes` on the
 * nested keys), and the controller only touches the relation whose top-level
 * key was actually sent.
 */
class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'required', 'string', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'min:50'],

            'region' => ['sometimes', 'required', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country_code' => ['sometimes', 'nullable', 'string', 'max:2'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:255'],

            'payment_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'species_ids' => ['sometimes', 'array'],
            'species_ids.*' => ['integer', 'exists:species,id'],

            'export_markets' => ['sometimes', 'array'],
            'export_markets.*.country_code' => ['required', 'string', 'max:2'],

            'contacts' => ['sometimes', 'array'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.title' => ['nullable', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:32'],
            'contacts.*.whatsapp' => ['nullable', 'string', 'max:32'],
            'contacts.*.is_public' => ['sometimes', 'boolean'],

            'gallery' => ['sometimes', 'array'],
            'gallery.*.image_path' => ['required', 'string'],
            'gallery.*.caption' => ['nullable', 'string', 'max:255'],
        ];
    }
}
