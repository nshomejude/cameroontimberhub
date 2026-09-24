<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            // Same rule object RegisterAccount uses for registration, so
            // password strength requirements never drift between the two
            // paths.
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
