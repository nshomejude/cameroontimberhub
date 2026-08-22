<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Declining is a recorded decision the supplier reads, so a reason is
 * mandatory — QuoteService::decline() throws on an empty one, and this turns
 * that into a 422 with a field-level message instead of a 500.
 */
class DeclineQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
