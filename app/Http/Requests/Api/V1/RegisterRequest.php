<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Auth\RegisterAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registration rules come straight from RegisterAccount, the shared action the
 * web form uses, so the two paths can never drift apart.
 *
 * The mobile client can register `buyer` or `supplier` accounts (product
 * scope: buyers AND suppliers AND staff). `account_type` defaults to
 * `buyer` when omitted, so the current app (which never sends the field)
 * keeps registering buyers exactly as before. A `supplier` submission goes
 * through the same `company_name`/`company_phone`/... fields
 * `RegisterAccount::rules()` already requires for company-forming account
 * types, and is created by the exact same `RegisterAccount` action the web
 * supplier-registration flow uses — no second write path.
 *
 * The other company-forming account types (`processor`, `artisan`,
 * `carbon_developer`, `logistics_partner`) and `carbon_buyer` stay web-only
 * for now; only `buyer`/`supplier` are exposed here.
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
        $rules = RegisterAccount::rules(forApi: true);

        $rules['account_type'] = ['required', Rule::in(['buyer', 'supplier'])];

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $accountType = $this->input('account_type');

        $this->merge([
            'account_type' => is_string($accountType) && $accountType !== '' ? $accountType : 'buyer',
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }
}
