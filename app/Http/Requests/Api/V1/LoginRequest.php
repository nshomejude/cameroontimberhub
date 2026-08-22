<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:180'],
            'password' => ['required', 'string'],
            // Names the device the token belongs to, so a buyer can tell one
            // handset from another when tokens are listed or revoked.
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
