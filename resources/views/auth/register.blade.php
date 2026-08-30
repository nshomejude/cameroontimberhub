@php
    /**
     * Register — built from the approved mobile and desktop mockups.
     *
     * Same strategy as the login screen: ONE form (so every input id and
     * `<label for>` stays unique), with the surrounding artwork swapped per
     * breakpoint. Mobile is a compact hero strip above a full-bleed card;
     * desktop is a left promo panel, the form, and a "why join" rail.
     */
    $benefits = [
        ['icon' => 'shield-check', 'title' => __('messages.register.benefit_1_title'), 'text' => __('messages.register.benefit_1_text')],
        ['icon' => 'globe-alt', 'title' => __('messages.register.benefit_2_title'), 'text' => __('messages.register.benefit_2_text')],
        ['icon' => 'lock-closed', 'title' => __('messages.register.benefit_3_title'), 'text' => __('messages.register.benefit_3_text')],
        ['icon' => 'chart-bar', 'title' => __('messages.register.benefit_4_title'), 'text' => __('messages.register.benefit_4_text')],
    ];
@endphp

<x-layouts.app
    :title="__('messages.register.title')"
    :description="__('messages.register.meta_description')"
    :noindex="true">

    <div class="bg-sand-50">
        <div class="mx-auto max-w-[1400px] lg:px-6 lg:py-8">
            <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] lg:overflow-hidden lg:rounded-3xl lg:border lg:border-sand-200 lg:bg-white lg:shadow-sm">

                {{-- ---------- Desktop promo panel (mockup 4) ---------- --}}
                <aside class="relative isolate hidden overflow-hidden bg-forest-950 lg:block">
                    <img src="{{ asset('img/hero/timber-logs-forest.jpg') }}" alt=""
                         width="1600" height="1400" aria-hidden="true"
                         class="absolute inset-0 h-full w-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-b from-forest-950 via-forest-950/90 to-forest-950/40" aria-hidden="true"></div>

                    <div class="relative flex h-full flex-col px-10 py-14">
                        <h2 class="max-w-sm text-[2.1rem] font-bold leading-[1.12] tracking-tight text-white">
                            {{ __('messages.register.promo_heading') }}
                        </h2>
                        <span class="mt-5 block h-1 w-14 rounded-full bg-timber-400" aria-hidden="true"></span>

                        <p class="mt-5 max-w-xs text-[1.125rem] leading-relaxed text-sand-200/90">
                            {{ __('messages.register.promo_body') }}
                        </p>

                        <ul class="mt-9 space-y-5">
                            @foreach ($benefits as $benefit)
                                <li class="flex gap-4">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-timber-400/40 bg-white/5 text-timber-300">
                                        <x-dynamic-component :component="'heroicon-o-'.$benefit['icon']" class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div>
                                        <p class="text-[1.125rem] font-semibold text-white">{{ $benefit['title'] }}</p>
                                        <p class="mt-0.5 max-w-[15rem] text-[1.0625rem] leading-relaxed text-sand-200/75">{{ $benefit['text'] }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        <p class="mt-auto flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-3.5 text-[1.0625rem] leading-snug text-sand-100">
                            <x-heroicon-o-lock-closed class="h-5 w-5 shrink-0 text-timber-300" aria-hidden="true" />
                            {{ __('messages.register.privacy_note') }}
                        </p>
                    </div>
                </aside>

                {{-- ---------- Form column ---------- --}}
                <div class="lg:px-10 lg:py-12">

                    {{-- Mobile hero strip (mockup 2) --}}
                    <div class="relative isolate overflow-hidden lg:hidden">
                        <img src="{{ asset('img/hero/timber-logs-mobile.jpg') }}" alt=""
                             width="828" height="420" fetchpriority="high" aria-hidden="true"
                             class="absolute inset-0 h-full w-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-r from-white/85 via-white/70 to-white/40" aria-hidden="true"></div>
                        <div class="relative flex items-center gap-4 px-5 pb-10 pt-6">
                            <img src="/brand/logo-600.png" alt="Cameroon Timber Hub" width="600" height="200" class="h-16 w-auto shrink-0">
                            <div>
                                <p class="text-[1.375rem] font-bold leading-tight text-forest-800">{{ __('messages.register.create_your_account') }}</p>
                                <p class="mt-1 text-[1.0625rem] leading-snug text-ink-soft">{{ __('messages.register.mobile_hero_subtitle') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="relative -mt-5 rounded-t-3xl bg-white px-5 pb-12 pt-7 lg:mt-0 lg:rounded-none lg:p-0">

                        <div class="hidden lg:block">
                            <h1 class="text-[1.75rem] font-bold tracking-tight text-forest-800">{{ __('messages.register.create_your_account') }}</h1>
                            <p class="mt-1.5 text-[1.125rem] text-ink-soft">
                                {{ __('messages.register.mobile_hero_subtitle') }}
                                @if ($stats['suppliers'] ?? null)
                                    <span class="block text-[1.0625rem]">
                                        {!! __('messages.register.verified_suppliers_stat', ['count' => '<strong data-stat="suppliers">'.number_format($stats['suppliers']).'</strong>', 'suppliers' => Str::plural('supplier', $stats['suppliers'])]) !!}
                                    </span>
                                @endif
                            </p>
                            <p class="mt-3 inline-flex items-center gap-2 rounded-lg bg-forest-50 px-3 py-2 text-[0.9375rem] font-medium text-forest-800">
                                <x-heroicon-o-shield-check class="h-4 w-4" aria-hidden="true" />
                                {{ __('messages.register.required_note') }}
                            </p>
                        </div>

                        <h2 class="sr-only lg:hidden">{{ __('messages.register.create_your_account') }}</h2>

                        <div class="mt-5 lg:mt-6">
                            <x-auth.error-summary />
                        </div>

                        <form method="POST" action="{{ route('register.store') }}" novalidate
                              class="mt-6 space-y-8"
                              x-data="{ accountType: '{{ old('account_type', 'buyer') }}' }">
                            @csrf

                            {{-- ===== Personal information ===== --}}
                            <section aria-labelledby="sec-personal">
                                <h2 id="sec-personal" class="flex items-center gap-2 border-b border-sand-200 pb-3 text-[1.125rem] font-bold text-forest-800">
                                    <x-heroicon-o-user class="h-5 w-5" aria-hidden="true" />
                                    {{ __('messages.register.section_personal') }}
                                </h2>

                                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                    <x-auth.field
                                        name="name"
                                        :label="__('messages.register.label_full_name')"
                                        icon="user"
                                        :placeholder="__('messages.register.placeholder_full_name')"
                                        autocomplete="name"
                                        maxlength="120"
                                        :required="true"
                                        :autofocus="true" />

                                    <x-auth.field
                                        name="email"
                                        :label="__('messages.register.label_email')"
                                        type="email"
                                        icon="envelope"
                                        :placeholder="__('messages.register.placeholder_email')"
                                        autocomplete="email"
                                        maxlength="180"
                                        :required="true" />
                                </div>
                            </section>

                            {{-- ===== Business information ===== --}}
                            <section aria-labelledby="sec-business">
                                <h2 id="sec-business" class="flex items-center gap-2 border-b border-sand-200 pb-3 text-[1.125rem] font-bold text-forest-800">
                                    <x-heroicon-o-briefcase class="h-5 w-5" aria-hidden="true" />
                                    {{ __('messages.register.section_business') }}
                                </h2>

                                <div class="mt-5 space-y-4">
                                    {{-- The account type is the real fork: "supplier" creates a pending
                                         Company and attaches the user as its owner on the company_user
                                         pivot; "buyer" creates a plain user with no company. --}}
                                    <fieldset>
                                        <legend class="text-[1.0625rem] font-semibold text-ink">
                                            {{ __('messages.register.i_am_a') }} <span aria-hidden="true" class="text-red-600">*</span><span class="sr-only">(required)</span>
                                        </legend>
                                        <div class="mt-1.5 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                            @foreach ([
                                                ['buyer', __('messages.register.account_type_buyer'), __('messages.register.account_type_buyer_hint'), 'shopping-bag'],
                                                ['supplier', __('messages.register.account_type_supplier'), __('messages.register.account_type_supplier_hint'), 'building-office-2'],
                                                ['processor', __('messages.register.account_type_processor'), __('messages.register.account_type_processor_hint'), 'cog-6-tooth'],
                                                ['artisan', __('messages.register.account_type_artisan'), __('messages.register.account_type_artisan_hint'), 'wrench-screwdriver'],
                                                ['logistics_partner', __('messages.register.account_type_logistics'), __('messages.register.account_type_logistics_hint'), 'truck'],
                                                ['carbon_developer', __('messages.register.account_type_carbon_developer'), __('messages.register.account_type_carbon_developer_hint'), 'globe-alt'],
                                                ['carbon_buyer', __('messages.register.account_type_carbon_buyer'), __('messages.register.account_type_carbon_buyer_hint'), 'banknotes'],
                                            ] as [$value, $label, $hint, $icon])
                                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3.5 transition focus-within:ring-2 focus-within:ring-forest-100"
                                                       :class="accountType === '{{ $value }}'
                                                           ? 'border-forest-700 bg-forest-50'
                                                           : 'border-sand-300 hover:border-forest-400'">
                                                    <input type="radio" name="account_type" value="{{ $value }}"
                                                           class="mt-1 h-4 w-4 shrink-0 border-sand-400 text-forest-700 focus:ring-0"
                                                           x-model="accountType"
                                                           @if ($errors->has('account_type')) aria-invalid="true" aria-describedby="auth-account-type-error" @endif
                                                           @checked(old('account_type', 'buyer') === $value)>
                                                    <span>
                                                        <span class="flex items-center gap-1.5 text-[1.0625rem] font-semibold text-ink">
                                                            <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4 text-forest-700" aria-hidden="true" />
                                                            {{ $label }}
                                                        </span>
                                                        <span class="mt-0.5 block text-[0.9375rem] text-ink-soft">{{ $hint }}</span>
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                        @error('account_type')
                                            <p id="auth-account-type-error" class="mt-1.5 text-[0.9375rem] font-medium text-red-700">{{ $message }}</p>
                                        @enderror
                                    </fieldset>

                                    {{-- Supplier-only: everything here lands on the new Company row. --}}
                                    <div x-show="['supplier','processor','artisan','logistics_partner','carbon_developer'].includes(accountType)" x-cloak class="space-y-4">
                                        <x-auth.field
                                            name="company_name"
                                            :label="__('messages.register.label_company_name')"
                                            icon="briefcase"
                                            :placeholder="__('messages.register.placeholder_company_name')"
                                            autocomplete="organization"
                                            maxlength="255"
                                            :help="__('messages.register.help_company_name')" />

                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <x-auth.field
                                                name="company_phone"
                                                :label="__('messages.register.label_phone')"
                                                type="tel"
                                                icon="phone"
                                                placeholder="+237 6XX XX XX XX"
                                                autocomplete="tel"
                                                maxlength="32" />

                                            <x-auth.field
                                                name="company_city"
                                                :label="__('messages.register.label_city')"
                                                icon="map-pin"
                                                :placeholder="__('messages.register.placeholder_city')"
                                                autocomplete="address-level2"
                                                maxlength="120" />
                                        </div>

                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <x-auth.field
                                                name="company_country"
                                                :label="__('messages.register.label_country')"
                                                type="select"
                                                icon="globe-alt"
                                                autocomplete="country"
                                                value="CM"
                                                :options="['CM' => __('messages.register.option_cameroon')]" />

                                            <x-auth.field
                                                name="company_registration_number"
                                                :label="__('messages.register.label_registration_number')"
                                                icon="identification"
                                                :placeholder="__('messages.register.placeholder_optional')"
                                                maxlength="100" />
                                        </div>
                                    </div>
                                </div>
                            </section>

                            {{-- ===== Security ===== --}}
                            <section aria-labelledby="sec-security">
                                <h2 id="sec-security" class="flex items-center gap-2 border-b border-sand-200 pb-3 text-[1.125rem] font-bold text-forest-800">
                                    <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                                    {{ __('messages.register.section_security') }}
                                </h2>

                                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                    <x-auth.field
                                        name="password"
                                        :label="__('messages.register.label_password')"
                                        type="password"
                                        icon="lock-closed"
                                        :placeholder="__('messages.register.placeholder_password')"
                                        autocomplete="new-password"
                                        :required="true"
                                        :toggle="true" />

                                    <x-auth.field
                                        name="password_confirmation"
                                        :label="__('messages.register.label_confirm_password')"
                                        type="password"
                                        icon="lock-closed"
                                        :placeholder="__('messages.register.placeholder_confirm_password')"
                                        autocomplete="new-password"
                                        :required="true"
                                        :toggle="true" />
                                </div>

                                {{-- These mirror the rules the server actually enforces. --}}
                                <ul class="mt-4 space-y-1.5 text-[1.0625rem] text-ink-soft">
                                    @foreach ([__('messages.register.rule_min_chars'), __('messages.register.rule_passwords_match')] as $rule)
                                        <li class="flex items-center gap-2">
                                            <x-heroicon-o-check-circle class="h-4 w-4 shrink-0 text-forest-600" aria-hidden="true" />
                                            {{ $rule }}
                                        </li>
                                    @endforeach
                                </ul>
                            </section>

                            <div>
                                <label for="auth-terms" class="flex items-start gap-3 text-[1.0625rem] leading-relaxed text-ink-soft">
                                    <input id="auth-terms" type="checkbox" name="terms" value="1" @checked(old('terms'))
                                           @if ($errors->has('terms')) aria-invalid="true" aria-describedby="auth-terms-error" @endif
                                           class="mt-1 h-4 w-4 shrink-0 rounded border-sand-400 text-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-600">
                                    <span>
                                        {{ __('messages.register.agree_terms_prefix') }}
                                        <a href="{{ url('/terms') }}" class="font-semibold text-forest-700 underline underline-offset-2">{{ __('messages.register.terms_of_service') }}</a>
                                        {{ __('messages.register.and') }}
                                        <a href="{{ url('/privacy') }}" class="font-semibold text-forest-700 underline underline-offset-2">{{ __('messages.register.privacy_policy') }}</a>
                                    </span>
                                </label>
                                @error('terms')
                                    <p id="auth-terms-error" class="mt-1.5 text-[0.9375rem] font-medium text-red-700">{{ $message }}</p>
                                @enderror
                            </div>

                            <button type="submit"
                                    class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-forest-800 px-6 py-3.5 text-[1.125rem] font-semibold text-white transition hover:bg-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-600 lg:w-auto lg:px-10">
                                <x-heroicon-o-user-plus class="h-5 w-5" aria-hidden="true" />
                                {{ __('messages.register.submit') }}
                            </button>
                        </form>

                        <p class="mt-7 text-center text-[1.0625rem] text-ink-soft lg:text-left">
                            {{ __('messages.register.already_have_account') }}
                            <a href="{{ route('login') }}"
                               class="font-semibold text-forest-700 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-600">{{ __('messages.register.sign_in') }}</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
