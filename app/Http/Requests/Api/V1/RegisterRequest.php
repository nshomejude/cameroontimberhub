<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Auth\RegisterAccount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registration rules come straight from RegisterAccount, the shared action the
 * web form uses, so the two paths can never drift apart.
 *
 * The mobile client can register ANY account type the web signup flow
 * supports — buyer, supplier, processor, artisan, carbon_developer,
 * carbon_buyer, logistics_partner (product scope: buyers AND sellers AND
 * transport AND every other population the web already onboards). We
 * deliberately do NOT hand-maintain a narrower `Rule::in([...])` here: an
 * earlier version of this file did (buyer/supplier only), and that's
 * exactly what left transport operators unable to sign up through the app
 * even after fleet endpoints existed for them — a FormRequest narrower
 * than the action behind it. `RegisterAccount::rules()` is now the single
 * source of truth for which account types exist and what each requires
 * (`company_name`/`company_phone`/... for every company-forming type, see
 * `RegisterAccount::companyFormingTypes()`); this class only asks for it.
 * `account_type` defaults to `buyer` when omitted, so the current app
 * (which may not send the field yet) keeps registering buyers exactly as
 * before.
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
        $accountType = $this->input('account_type');

        $this->merge([
            'account_type' => is_string($accountType) && $accountType !== '' ? $accountType : 'buyer',
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }
}
