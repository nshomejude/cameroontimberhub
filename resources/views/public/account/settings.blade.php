@php
    $field = 'mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-4 py-2.5 text-[1.0625rem] text-ink outline-none focus:border-forest-500';
    $label = 'block text-[1.0625rem] font-medium text-ink';
    $error = 'mt-1 text-[0.9375rem] text-red-700';
    $btn = 'inline-flex items-center gap-2 rounded-full bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800';
    // Endonyms: a language is always listed in its own name.
    $localeNames = ['en' => 'English', 'fr' => 'Français', 'zh_CN' => '简体中文', 'th' => 'ไทย', 'vi' => 'Tiếng Việt', 'it' => 'Italiano', 'es' => 'Español', 'de' => 'Deutsch'];
    $checkbox ='h-5 w-5 rounded border-sand-300 text-forest-700 focus:ring-forest-500';
@endphp

<x-layouts.account
    :title="__('messages.account_center.settings_title')"
    :heading="__('messages.account_center.settings_title')"
    :subheading="__('messages.account_center.settings_subheading')">

    <x-account.flash />

    <div class="grid gap-5 xl:grid-cols-2">
        {{-- Profile --}}
        <x-account.panel :title="__('messages.account_center.profile_title')" :subtitle="__('messages.account_center.profile_subtitle')">
            <form method="POST" action="{{ route('account.settings.profile') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label for="name" class="{{ $label }}">{{ __('messages.account_center.field_name') }}</label>
                    <input id="name" name="name" type="text" required maxlength="255" autocomplete="name"
                           value="{{ old('name', $user->name) }}" class="{{ $field }}">
                    @error('name', 'profile') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email" class="{{ $label }}">{{ __('messages.account_center.field_email') }}</label>
                    <input id="email" type="email" value="{{ $user->email }}" disabled class="{{ $field }} opacity-70">
                    <p class="mt-1 text-[0.9375rem] text-ink-soft">{{ __('messages.account_center.field_email_hint') }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="phone" class="{{ $label }}">{{ __('messages.account_center.field_phone') }}</label>
                        <input id="phone" name="phone" type="tel" maxlength="32" autocomplete="tel"
                               value="{{ old('phone', $user->phone) }}" class="{{ $field }}">
                        @error('phone', 'profile') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="locale" class="{{ $label }}">{{ __('messages.account_center.field_locale') }}</label>
                        <select id="locale" name="locale" class="{{ $field }}">
                            @foreach ($locales as $locale)
                                <option value="{{ $locale }}" @selected(old('locale', $user->locale ?? app()->getLocale()) === $locale)>
                                    {{ $localeNames[$locale] ?? $locale }}
                                </option>
                            @endforeach
                        </select>
                        @error('locale', 'profile') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </div>
                <button type="submit" class="{{ $btn }}">{{ __('messages.account_center.save_profile') }}</button>
            </form>
        </x-account.panel>

        {{-- Password --}}
        <x-account.panel :title="__('messages.account_center.password_title')" :subtitle="__('messages.account_center.password_subtitle')">
            <form method="POST" action="{{ route('account.settings.password') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label for="current_password" class="{{ $label }}">{{ __('messages.account_center.field_current_password') }}</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="{{ $field }}">
                    @error('current_password', 'password') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password" class="{{ $label }}">{{ __('messages.account_center.field_new_password') }}</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password" class="{{ $field }}">
                    @error('password', 'password') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password_confirmation" class="{{ $label }}">{{ __('messages.account_center.field_confirm_password') }}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="{{ $field }}">
                </div>
                <button type="submit" class="{{ $btn }}">{{ __('messages.account_center.save_password') }}</button>
            </form>
        </x-account.panel>

        {{-- Two-factor --}}
        <x-account.panel :title="__('messages.account_center.security_title')">
            <div class="flex flex-wrap items-center gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-forest-50 text-forest-700">
                    <x-heroicon-o-shield-check class="h-6 w-6" />
                </span>
                <p class="min-w-0 flex-1 text-[1.0625rem] text-ink-soft">
                    {{ $user->two_factor_confirmed_at ? __('messages.account_center.security_on') : __('messages.account_center.security_off') }}
                </p>
                <a href="{{ route('two-factor.show') }}"
                   class="inline-flex items-center gap-1.5 rounded-full border border-forest-700 px-4 py-2 text-[1.0625rem] font-semibold text-forest-700 transition hover:bg-forest-50">
                    {{ __('messages.account_center.security_manage') }} <x-heroicon-m-arrow-right class="h-4 w-4" />
                </a>
            </div>
        </x-account.panel>

        {{-- Notification preferences --}}
        <x-account.panel :title="__('messages.account_center.prefs_title')" :subtitle="__('messages.account_center.prefs_subtitle')">
            <form method="POST" action="{{ route('account.settings.preferences') }}" class="space-y-5">
                @csrf
                @method('PUT')
                <fieldset>
                    <legend class="text-[0.9375rem] font-bold uppercase tracking-[0.12em] text-ink-soft">{{ __('messages.account_center.prefs_channels') }}</legend>
                    <div class="mt-2 space-y-2">
                        @foreach ($channelKeys as $key)
                            <label class="flex items-center gap-3 text-[1.0625rem] text-ink">
                                <input type="hidden" name="channels[{{ $key }}]" value="0">
                                <input type="checkbox" name="channels[{{ $key }}]" value="1" class="{{ $checkbox }}"
                                       @checked($preferences->channels[$key] ?? true)>
                                {{ __('messages.account_center.prefs_channel_'.$key) }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <fieldset>
                    <legend class="text-[0.9375rem] font-bold uppercase tracking-[0.12em] text-ink-soft">{{ __('messages.account_center.prefs_types') }}</legend>
                    <div class="mt-2 space-y-2">
                        @foreach ($typeKeys as $key)
                            <label class="flex items-center gap-3 text-[1.0625rem] text-ink">
                                <input type="hidden" name="types[{{ $key }}]" value="0">
                                <input type="checkbox" name="types[{{ $key }}]" value="1" class="{{ $checkbox }}"
                                       @checked($preferences->types[$key] ?? true)>
                                {{ __('messages.account_center.prefs_type_'.$key) }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <button type="submit" class="{{ $btn }}">{{ __('messages.account_center.save_preferences') }}</button>
            </form>
        </x-account.panel>

        {{-- Referral payouts --}}
        <x-account.panel id="referral-payouts" :title="__('messages.account_center.payout_title')" :subtitle="__('messages.account_center.payout_subtitle')">
            <form method="POST" action="{{ route('account.settings.referral-payout') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <p class="text-[1.0625rem] text-ink-soft">
                    @if ($payoutProfile?->maskedPaypalEmail())
                        {{ __('messages.account_center.payout_current', ['email' => $payoutProfile->maskedPaypalEmail()]) }}
                    @else
                        {{ __('messages.account_center.payout_none') }}
                    @endif
                </p>
                @unless ($paypalPayoutsAvailable)
                    <p class="text-[0.9375rem] text-ink-soft">{{ __('messages.account_center.payout_paypal_unavailable') }}</p>
                @endunless
                <div>
                    <label for="paypal_payout_email" class="{{ $label }}">{{ __('messages.account_center.payout_field_email') }}</label>
                    <input id="paypal_payout_email" name="paypal_payout_email" type="email" maxlength="254" autocomplete="off"
                           value="{{ old('paypal_payout_email') }}" class="{{ $field }}">
                    <p class="mt-1 text-[0.9375rem] text-ink-soft">{{ __('messages.account_center.payout_field_hint') }}</p>
                    @error('paypal_payout_email', 'payout') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="{{ $btn }}">{{ __('messages.account_center.payout_save') }}</button>
            </form>

            <h3 class="mt-6 text-[0.9375rem] font-bold uppercase tracking-[0.12em] text-ink-soft">{{ __('messages.account_center.payout_earnings') }}</h3>
            @forelse ($referralEarnings as $earning)
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-b border-sand-200 py-2 text-[1.0625rem] last:border-0">
                    <span class="text-ink">{{ $earning->amountLabel() }} <span class="text-ink-soft">· {{ $earning->source_reference }}</span></span>
                    <span class="text-ink-soft">{{ \App\Models\ReferralEarning::payoutStatusLabel($earning->payoutStatus()) }}</span>
                </div>
            @empty
                <p class="mt-2 text-[1.0625rem] text-ink-soft">{{ __('messages.account_center.payout_earnings_empty') }}</p>
            @endforelse
        </x-account.panel>
    </div>
</x-layouts.account>
