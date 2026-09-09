<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for a threaded reply on a dispute — mirrors
 * `Public\DisputeController::reply()`'s own validation exactly. Evidence
 * upload (with a file) stays web-only for this pass; see
 * `DisputeController@reply`'s docblock in Api/V1 for why.
 */
class ReplyDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4000'],
        ];
    }
}
