<?php

namespace App\Actions\Auth;

use App\Enums\CompanyStatus;
use App\Enums\CompanyUserRole;
use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\User;
use App\Services\Referrals\ReferralService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * The single account-creation write path.
 *
 * Extracted from RegisterController so the web form and the mobile API create
 * accounts identically: same validation rules, same hashing, same supplier
 * company/ownership rules, same terms-consent record. Account-free RFQs
 * already sitting under the address are adopted only after the email is
 * verified (AdoptGuestRfqsOnEmailVerified), never here. Both callers validate with `rules()` and then hand the
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
    /**
     * Account-capability roles that create a Company row at registration.
     * Mirrors 5 of the 7 ACCOUNT_ROLES seeded by RolesAndPermissionsSeeder;
     * `buyer` and `carbon_buyer` are pure-demand roles with no company.
     *
     * @return list<string>
     */
    public static function companyFormingTypes(): array
    {
        return ['supplier', 'processor', 'artisan', 'logistics_partner', 'carbon_developer'];
    }

    /** Canonical stored form of an email address: trimmed, lower-cased. */
    public static function normaliseEmail(mixed $email): mixed
    {
        return is_string($email) ? strtolower(trim($email)) : $email;
    }

    /** Carbon account types, dormant until config('timber.signup.carbon_enabled'). */
    public const CARBON_TYPES = ['carbon_developer', 'carbon_buyer'];

    public static function carbonSignupEnabled(): bool
    {
        return (bool) config('timber.signup.carbon_enabled', false);
    }

    /**
     * Account types a new user may self-register as right now.
     *
     * @return list<string>
     */
    public static function selectableTypes(): array
    {
        $types = ['buyer', 'supplier', 'processor', 'artisan', 'logistics_partner'];

        return self::carbonSignupEnabled() ? [...$types, ...self::CARBON_TYPES] : $types;
    }

    public static function rules(bool $forApi = false): array
    {
        $excludeUnlessCompanyForming = Rule::excludeIf(
            fn () => ! in_array(request()->input('account_type'), self::companyFormingTypes(), true)
        );

        $rules = [
            'account_type' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (in_array($value, self::CARBON_TYPES, true) && ! self::carbonSignupEnabled()) {
                    $fail(__('messages.register.carbon_coming_soon'));

                    return;
                }

                if (! in_array($value, self::selectableTypes(), true)) {
                    $fail(__('validation.in', ['attribute' => $attribute]));
                }
            }],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:180', function (string $attribute, mixed $value, \Closure $fail): void {
                // Case-insensitive uniqueness: legacy rows may be mixed-case.
                if (is_string($value) && \App\Models\User::whereRaw('lower(email) = ?', [strtolower(trim($value))])->exists()) {
                    $fail(__('validation.unique', ['attribute' => $attribute]));
                }
            }],
            'company_name' => [$excludeUnlessCompanyForming, 'required', 'string', 'min:2', 'max:255'],
            'company_phone' => [$excludeUnlessCompanyForming, 'nullable', 'string', 'max:32'],
            'company_city' => [$excludeUnlessCompanyForming, 'nullable', 'string', 'max:120'],
            'company_country' => [$excludeUnlessCompanyForming, 'nullable', 'string', 'size:2'],
            'company_registration_number' => [$excludeUnlessCompanyForming, 'nullable', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
            // Optional referral code (CTH-XXXXXX). Unknown codes and
            // self-referrals are rejected here, before any row is written.
            'referral_code' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail): void {
                $service = app(ReferralService::class);
                $referrer = $service->resolveReferrer(is_string($value) ? $value : null);

                if ($referrer === null) {
                    $fail(__('This referral code is not valid.'));

                    return;
                }

                $email = strtolower(trim((string) request()->input('email')));
                if ($service->selfReferralReason($referrer, $email) !== null) {
                    $fail(__('You cannot use your own referral code.'));
                }
            }],
        ];

        if ($forApi) {
            $rules['password'] = ['required', Password::defaults()];
            unset($rules['terms']);
            // A native client presents its own terms screen but must still
            // send explicit consent, which is recorded on the user row.
            $rules['terms_accepted'] = ['accepted'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $data A payload already validated by rules(). */
    public function __invoke(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => self::normaliseEmail($data['email']),
                'password' => $data['password'],
            ]);

            // Consent record (terms of service). Both callers validate the
            // consent field as `accepted` before reaching here.
            $user->forceFill([
                'terms_accepted_at' => now(),
                'terms_version' => (string) config('app.terms_version', '1'),
            ])->save();

            $accountType = $data['account_type'] ?? 'buyer';

            $organisationTypeByAccountType = [
                'supplier' => OrganisationType::Supplier,
                'processor' => OrganisationType::Processor,
                'artisan' => OrganisationType::Artisan,
                'logistics_partner' => OrganisationType::Logistics,
                'carbon_developer' => OrganisationType::CarbonDeveloper,
            ];

            if (in_array($accountType, self::companyFormingTypes(), true)) {
                // Only the non-nullable columns; the rest of the profile is
                // completed by the owner in the exporter panel.
                $company = Company::create([
                    'legal_name' => $data['company_name'],
                    'type' => $organisationTypeByAccountType[$accountType],
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
                $user->assignRole(Role::findOrCreate($accountType, 'web'));
            } else {
                // 'buyer' and 'carbon_buyer': pure-demand roles, no company.
                // findOrCreate so an environment where the roles seeder has
                // not run yet does not 500 on signup.
                $user->assignRole(Role::findOrCreate($accountType, 'web'));
            }

            // Account-free RFQs this address submitted earlier are NOT adopted
            // here: the address is unverified at this point. They are adopted
            // by AdoptGuestRfqsOnEmailVerified once the owner clicks the
            // verification link.

            // Referral programme: record who referred this account (and its
            // company). The referrer is notified after commit.
            if (filled($data['referral_code'] ?? null)) {
                app(ReferralService::class)->attachReferrer($user, $company ?? null, $data['referral_code']);
            }

            return $user;
        });
    }
}
