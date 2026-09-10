@php $data = $wizard->step('contact'); @endphp

<fieldset class="space-y-5">
    <legend class="sr-only">{{ __('messages.rfq_wizard.contact_details_legend') }}</legend>

    @auth
        <p class="rounded-xl bg-forest-50 px-4 py-3 text-[1.0625rem] text-forest-900 dark:bg-forest-950 dark:text-forest-200">
            <x-heroicon-m-user-circle class="mr-1 inline h-4 w-4 align-text-bottom" />
            {{ __('messages.rfq_wizard.prefilled_notice') }}
        </p>
    @endauth

    <div class="grid gap-5 sm:grid-cols-2">
        <x-rfq.field name="buyer_name" :label="__('messages.rfq_wizard.your_name')" required
                     :value="$data['buyer_name'] ?? null"
                     :autofocus="$errors->has('buyer_name') || ! $errors->any()"
                     autocomplete="name" maxlength="120" />

        <x-rfq.field name="buyer_email" :label="__('messages.rfq_wizard.email_address')" type="email" required
                     :value="$data['buyer_email'] ?? null"
                     :autofocus="$errors->has('buyer_email')"
                     autocomplete="email" maxlength="180"
                     :help="__('messages.rfq_wizard.email_confirm_help')" />

        <x-rfq.field name="buyer_company" :label="__('messages.rfq_wizard.company')"
                     :value="$data['buyer_company'] ?? null"
                     :autofocus="$errors->has('buyer_company')"
                     autocomplete="organization" maxlength="160" />

        <x-rfq.field name="buyer_phone" :label="__('messages.rfq_wizard.phone')"
                     :value="$data['buyer_phone'] ?? null"
                     :autofocus="$errors->has('buyer_phone')"
                     autocomplete="tel" :placeholder="__('messages.rfq_wizard.phone_ph')"
                     :help="__('messages.rfq_wizard.phone_help')" />

        <x-rfq.field name="buyer_country_code" :label="__('messages.rfq_wizard.your_country')" required
                     :value="$data['buyer_country_code'] ?? null"
                     :autofocus="$errors->has('buyer_country_code')"
                     :placeholder="__('messages.rfq_wizard.your_country_ph')" maxlength="2" autocomplete="country"
                     :help="__('messages.rfq_wizard.iso_country_help')" />
    </div>

    <div class="rounded-xl border border-sand-200 bg-sand-100 px-4 py-4 dark:border-[#3a352e] dark:bg-[#26241e]">
        <x-rfq.field name="consent" type="checkbox" required
                     :value="$data['consent'] ?? null"
                     :autofocus="$errors->has('consent')"
                     :label="__('messages.rfq_wizard.consent')" />
    </div>
</fieldset>
