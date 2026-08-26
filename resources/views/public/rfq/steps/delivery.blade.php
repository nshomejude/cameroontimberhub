@php
    $data = $wizard->step('delivery');
    $incotermOptions = collect($incoterms)->mapWithKeys(fn ($i) => [$i->value => $i->label()])->all();
    $currencyOptions = collect($currencies)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
@endphp

<fieldset class="space-y-5">
    <legend class="sr-only">Delivery and business terms</legend>

    <div class="grid gap-5 sm:grid-cols-2">
        <x-rfq.field name="destination_country_code" label="Destination country" required
                     :value="$data['destination_country_code'] ?? null"
                     :autofocus="$errors->has('destination_country_code') || ! $errors->any()"
                     placeholder="e.g. NL"
                     help="Two-letter ISO country code for the delivery destination."
                     maxlength="2" autocomplete="country" />

        <x-rfq.field name="shipping_port" label="Preferred port or delivery point"
                     :value="$data['shipping_port'] ?? null"
                     :autofocus="$errors->has('shipping_port')"
                     placeholder="e.g. Rotterdam" maxlength="160" />

        <x-rfq.field name="incoterm" label="Delivery terms (Incoterm)" type="select"
                     :value="$data['incoterm'] ?? null"
                     :autofocus="$errors->has('incoterm')"
                     placeholder="Not specified"
                     :options="$incotermOptions"
                     help="Incoterms® 2020. Leave blank if the exporter should propose one." />

        <div class="grid grid-cols-2 gap-3">
            <x-rfq.field name="target_amount" label="Target price" type="number" step="0.01" min="0"
                         :value="$data['target_amount'] ?? null"
                         :autofocus="$errors->has('target_amount')"
                         placeholder="e.g. 450" />
            <x-rfq.field name="target_currency" label="Currency" type="select"
                         :value="$data['target_currency'] ?? null"
                         :autofocus="$errors->has('target_currency')"
                         placeholder="—" :options="$currencyOptions" />
        </div>
    </div>

    <x-rfq.field name="notes" label="Requirement details" type="textarea" rows="6" required
                 :value="$data['notes'] ?? null"
                 :autofocus="$errors->has('notes')"
                 maxlength="4000"
                 placeholder="Grades, certification (FSC / legality), packaging, payment terms, timelines, anything else an exporter needs to quote accurately."
                 help="Between 20 and 4000 characters." />
</fieldset>
