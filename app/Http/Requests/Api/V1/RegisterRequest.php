<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Auth\RegisterAccount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registration rules come straight from RegisterAccount, the shared action the
 * web form uses, so the two paths can never drift apart.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return RegisterAccount::rules(forApi: true);
    }

    protected function prepareForValidation(): void
    {
        // The mobile client is a buyer client; supplier onboarding stays on the
        // web, where the exporter panel can pick the account up.
        $this->merge([
            'account_type' => 'buyer',
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }
}
