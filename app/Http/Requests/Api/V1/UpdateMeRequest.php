<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Reuses SetLocale::SUPPORTED — the same list the middleware
            // already accepts from the session/header — so there is exactly
            // one place the supported-locale list is defined.
            'locale' => ['sometimes', 'nullable', Rule::in(SetLocale::SUPPORTED)],
        ];
    }
}
