<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DisputeCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload to open a new dispute on an order — mirrors
 * `Public\DisputeController::store()`'s own validation exactly, so the API
 * and web forms accept/reject the same input.
 */
class OpenDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:'.implode(',', DisputeCategory::values())],
            'description' => ['required', 'string', 'max:4000'],
        ];
    }
}
