@php $data = $wizard->step('contact'); @endphp

<fieldset class="space-y-5">
    <legend class="sr-only">Your contact details</legend>

    @auth
        <p class="rounded-xl bg-forest-50 px-4 py-3 text-[0.8125rem] text-forest-900 dark:bg-forest-950 dark:text-forest-200">
            <x-heroicon-m-user-circle class="mr-1 inline h-4 w-4 align-text-bottom" />
            We've pre-filled your account details. Edit anything that should be different for this request.
        </p>
    @endauth

    <div class="grid gap-5 sm:grid-cols-2">
        <x-rfq.field name="buyer_name" label="Your name" required
                     :value="$data['buyer_name'] ?? null"
                     :autofocus="$errors->has('buyer_name') || ! $errors->any()"
                     autocomplete="name" maxlength="120" />

        <x-rfq.field name="buyer_email" label="Email address" type="email" required
                     :value="$data['buyer_email'] ?? null"
                     :autofocus="$errors->has('buyer_email')"
                     autocomplete="email" maxlength="180"
                     help="We send a confirmation link here — your request is only actioned once you click it." />

        <x-rfq.field name="buyer_company" label="Company"
                     :value="$data['buyer_company'] ?? null"
                     :autofocus="$errors->has('buyer_company')"
                     autocomplete="organization" maxlength="160" />

        <x-rfq.field name="buyer_phone" label="Phone"
                     :value="$data['buyer_phone'] ?? null"
                     :autofocus="$errors->has('buyer_phone')"
                     autocomplete="tel" placeholder="e.g. +33612345678"
                     help="International format, digits only after the +." />

        <x-rfq.field name="buyer_country_code" label="Your country" required
                     :value="$data['buyer_country_code'] ?? null"
                     :autofocus="$errors->has('buyer_country_code')"
                     placeholder="e.g. FR" maxlength="2" autocomplete="country"
                     help="Two-letter ISO country code." />
    </div>

    <div class="rounded-xl border border-sand-200 bg-sand-100 px-4 py-4 dark:border-[#3a352e] dark:bg-[#26241e]">
        <x-rfq.field name="consent" type="checkbox" required
                     :value="$data['consent'] ?? null"
                     :autofocus="$errors->has('consent')"
                     label="I consent to Cameroon Timber Hub sharing this request with verified exporters and contacting me by email about it." />
    </div>
</fieldset>
