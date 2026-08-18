<?php

namespace App\Http\Controllers\Auth;

use App\Enums\CompanyStatus;
use App\Enums\CompanyUserRole;
use App\Http\Controllers\Auth\Concerns\ProvidesAuthPageStats;
use App\Http\Controllers\Auth\Concerns\RedirectsAfterAuth;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisterController extends Controller
{
    use ProvidesAuthPageStats, RedirectsAfterAuth;

    public function create(): View
    {
        return view('auth.register', ['stats' => $this->authPageStats()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
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
        ], [], [
            'terms' => 'terms of service',
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            if ($data['account_type'] === 'supplier') {
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
            }

            // Adopt any account-free RFQs this address submitted earlier, so
            // they appear in the new account without a signed link.
            Rfq::whereNull('user_id')
                ->whereRaw('lower(buyer_email) = ?', [strtolower($data['email'])])
                ->update(['user_id' => $user->id]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended($this->redirectPathFor($user));
    }
}
