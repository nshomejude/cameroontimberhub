<?php

namespace App\Http\Requests\Api\V1;

use App\Services\RfqWizard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RFQ creation payload for the mobile client.
 *
 * The rules are RfqWizard's own — the whole wizard's rule set, flattened —
 * minus the two browser-form artefacts: the `consent` checkbox (the native
 * client presents its own consent copy) and the contact block, which the API
 * takes from the authenticated account rather than from the request body. A
 * buyer cannot raise an RFQ under someone else's name or address through this
 * endpoint, because those fields are not accepted at all.
 */
class StoreRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = RfqWizard::rules();

        // Identity comes from the token, never the body.
        unset(
            $rules['consent'],
            $rules['buyer_name'],
            $rules['buyer_email'],
            $rules['buyer_company'],
            $rules['buyer_phone'],
        );

        $rules['title'] = ['required', 'string', 'min:3', 'max:160'];

        // Anti-spam fields the API client may still send (honeypot + render
        // timestamp); IntakeService inspects them before anything is written.
        $rules['website'] = ['nullable', 'string', 'max:255'];
        $rules['form_rendered_at'] = ['nullable', 'integer'];

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return RfqWizard::messages();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return RfqWizard::attributes();
    }
}
