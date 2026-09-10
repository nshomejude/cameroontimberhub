@php
    $data = $wizard->step('delivery');
    $incotermOptions = collect($incoterms)->mapWithKeys(fn ($i) => [$i->value => $i->label()])->all();
    $currencyOptions = collect($currencies)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
@endphp

<fieldset class="space-y-5">
    <legend class="sr-only">{{ __('messages.rfq_wizard.delivery_legend') }}</legend>

    <div class="grid gap-5 sm:grid-cols-2">
        <x-rfq.field name="destination_country_code" :label="__('messages.rfq_wizard.destination_country')" required
                     :value="$data['destination_country_code'] ?? null"
                     :autofocus="$errors->has('destination_country_code') || ! $errors->any()"
                     :placeholder="__('messages.rfq_wizard.destination_country_ph')"
                     :help="__('messages.rfq_wizard.destination_country_help')"
                     maxlength="2" autocomplete="country" />

        <x-rfq.field name="shipping_port" :label="__('messages.rfq_wizard.preferred_port')"
                     :value="$data['shipping_port'] ?? null"
                     :autofocus="$errors->has('shipping_port')"
                     :placeholder="__('messages.rfq_wizard.preferred_port_ph')" maxlength="160" />

        <x-rfq.field name="incoterm" :label="__('messages.rfq_wizard.delivery_terms')" type="select"
                     :value="$data['incoterm'] ?? null"
                     :autofocus="$errors->has('incoterm')"
                     :placeholder="__('messages.rfq_wizard.incoterm_not_specified')"
                     :options="$incotermOptions"
                     :help="__('messages.rfq_wizard.incoterm_help')" />

        <div class="grid grid-cols-2 gap-3">
            <x-rfq.field name="target_amount" :label="__('messages.rfq_wizard.target_price')" type="number" step="0.01" min="0"
                         :value="$data['target_amount'] ?? null"
                         :autofocus="$errors->has('target_amount')"
                         :placeholder="__('messages.rfq_wizard.target_price_ph')" />
            <x-rfq.field name="target_currency" :label="__('messages.rfq_wizard.currency')" type="select"
                         :value="$data['target_currency'] ?? null"
                         :autofocus="$errors->has('target_currency')"
                         placeholder="—" :options="$currencyOptions" />
        </div>
    </div>

    <x-rfq.field name="notes" :label="__('messages.rfq_wizard.requirement_details')" type="textarea" rows="6" required
                 :value="$data['notes'] ?? null"
                 :autofocus="$errors->has('notes')"
                 maxlength="4000"
                 :placeholder="__('messages.rfq_wizard.requirement_details_ph')"
                 :help="__('messages.rfq_wizard.requirement_details_help')" />
</fieldset>
