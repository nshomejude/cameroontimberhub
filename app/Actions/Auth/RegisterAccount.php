<?php

namespace App\Actions\Auth;

use App\Enums\CompanyStatus;
use App\Enums\CompanyUserRole;
use App\Models\Company;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The single account-creation write path.
 *
 * Extracted from RegisterController so the web form and the mobile API create
 * accounts identically: same validation rules, same hashing, same supplier
 * company/ownership rules, same adoption of account-free RFQs already sitting
 * under that address. Both callers validate with `rules()` and then hand the
 * validated payload here; neither re-implements any of it.
 */
class RegisterAccount
{
    /**
     * Validation rules for a registration payload.
     *
     * `$forApi` drops the two browser-form affordances (`terms` checkbox and
     * `password_confirmation`) — a native client presents its own terms consent
     * and has no second password field — while leaving every rule that protects
     * the data itself untouched.
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $forApi = false): array
    {
        $rules = [
            'account_type' => ['required', Rule::in(['buyer', 'supplier'])],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:180', Rule::unique('users', 'email')],
            'company_name' => ['exclude_unless:account_type,supplier', 'required', 'string', 'min:2', 'max:255'],
            'company_phone' => ['exclude_unless:account_type,supplier', 'nullable', 'string', 'max:32'],
            'company_city' => ['exclude_unless:account_type,supplier', 'nullable', 'string', 'max:120'],
            'company_country' => ['exclude_unless:account_type,supplier', 'nullable', 'string', 'size:2'],
            'company_registration_number' => ['exclude_unless:account_type,supplier', 'nullable', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
        ];

        if ($forApi) {
            $rules['password'] = ['required', Password::defaults()];
            unset($rules['terms']);
        }

        return $rules;
    }

    /** @param array<string, mixed> $data A payload already validated by rules(). */
    public function __invoke(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            if (($data['account_type'] ?? 'buyer') === 'supplier') {
                // Only the non-nullable columns; the rest of the profile is
                // completed by the owner in the exporter panel.
                $company = Company::create([
                    'legal_name' => $data['company_name'],
                    'status' => CompanyStatus::Pending,
                    'created_by' => $user->id,
                    'phone' => $data['company_phone'] ?? null,
                    'city' => $data['company_city'] ?? null,
                    'country_code' => $data['company_country'] ?? 'CM',
                    'registration_number' => $data['company_registration_number'] ?? null,
                ]);

                $company->users()->attach($user, [
                    'role' => CompanyUserRole::Owner->value,
                    'is_primary' => true,
                ]);

                // Account-capability role (brief §3.1), distinct from the
                // company_user pivot role above -- see RolesAndPermissionsSeeder.
                $user->assignRole('supplier');
            } else {
                $user->assignRole('buyer');
            }

            // Adopt any account-free RFQs this address submitted earlier, so
            // they appear in the new account without a signed link.
            Rfq::whereNull('user_id')
                ->whereRaw('lower(buyer_email) = ?', [strtolower($data['email'])])
                ->update(['user_id' => $user->id]);

            return $user;
        });
    }
}
