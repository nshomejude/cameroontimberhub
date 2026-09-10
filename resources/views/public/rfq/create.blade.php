@php($field = 'w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-3 py-2.5 text-ink dark:text-[#f1ece1] focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none')
<x-layouts.app
    :title="__('messages.rfq_wizard.title')"
    :description="__('messages.rfq_wizard.meta_description')">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-3xl px-4 py-12">
            <p class="eyebrow">{{ __('messages.rfq_wizard.no_account_needed') }}</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.title') }}</h1>
            <p class="mt-3 text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.rfq_wizard.intro_create') }}</p>
            <p class="mt-4 text-sm text-ink-soft dark:text-[#b3ab9b]">
                {{ __('messages.rfq_wizard.sourcing_domestically') }}
                <a href="{{ route('rfq.create.manufacturing') }}" class="font-semibold text-forest-700 underline underline-offset-2 dark:text-forest-300">{{ __('messages.rfq_wizard.manufacturing_rfq') }}</a>
                · {{ __('messages.rfq_wizard.need_transport') }}
                <a href="{{ route('rfq.create.transport') }}" class="font-semibold text-forest-700 underline underline-offset-2 dark:text-forest-300">{{ __('messages.rfq_wizard.transport_rfq') }}</a>
            </p>
        </div>
    </section>

    <section class="mx-auto max-w-3xl px-4 py-10">
        @if ($shortlist->isNotEmpty())
            <div class="mb-6 rounded-2xl border border-forest-200 bg-forest-50 p-5">
                <h2 class="flex items-center gap-2 text-[1rem] font-bold text-forest-900">
                    <x-heroicon-o-clipboard-document-list class="h-5 w-5" />
                    {{ __('messages.rfq_wizard.your_rfq_list', ['count' => $shortlist->count()]) }}
                </h2>
                <p class="mt-1 text-[1.0625rem] text-forest-800">{{ __('messages.rfq_wizard.rfq_list_included') }}</p>
                <ul class="mt-3 space-y-2">
                    @foreach ($shortlist as $item)
                        <li class="flex items-center gap-3 rounded-lg bg-white px-3 py-2">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('products.show', $item->slug) }}" class="block truncate text-[1.0625rem] font-semibold text-ink hover:text-forest-800">{{ $item->name }}</a>
                                <p class="truncate text-[0.9375rem] text-ink-soft">{{ $item->company?->name }}</p>
                            </div>
                            <form method="POST" action="{{ route('rfq-list.destroy', $item->slug) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded p-1.5 text-ink-soft transition hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                                        aria-label="{{ __('messages.rfq_wizard.remove_from_list', ['name' => $item->name]) }}">
                                    <x-heroicon-o-x-mark class="h-4 w-4" />
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                {{ __('messages.rfq_wizard.fix_highlighted') }}
            </div>
        @endif

        <form method="POST" action="{{ route('rfq.store') }}" class="space-y-8">
            @csrf
            <input type="hidden" name="form_rendered_at" value="{{ $formRenderedAt }}">
            <input type="hidden" name="source" value="request_quote">
            <div class="hidden" aria-hidden="true">
                <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <fieldset class="space-y-4">
                <legend class="font-display text-xl font-semibold text-forest-900 dark:text-sand-100">{{ __('messages.rfq_wizard.what_you_need') }}</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.rfq_wizard.species_from_catalog') }}</label>
                        <select name="species_id" class="{{ $field }}">
                            <option value="">{{ __('messages.rfq_wizard.choose_or_type') }}</option>
                            @foreach ($species as $sp)
                                <option value="{{ $sp->id }}" @selected(old('species_id') == $sp->id || (! old('species_id') && ($prefillSpecies === $sp->slug || $prefillSpeciesId === $sp->id)))>{{ $sp->common_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.rfq_wizard.species_free_text_alt') }}</label>
                        <input type="text" name="species_text" value="{{ old('species_text') }}" class="{{ $field }}" placeholder="e.g. Sapele">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.rfq_wizard.product_form') }} *</label>
                        <select name="form" class="{{ $field }}" required>
                            @foreach (['logs', 'sawn', 'veneer', 'plywood', 'other'] as $f)
                                <option value="{{ $f }}" @selected(old('form') === $f)>{{ \App\Enums\TimberForm::from($f)->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.rfq_wizard.quantity') }} *</label>
                            <input type="number" step="0.01" name="quantity" value="{{ old('quantity', $prefillQuantity) }}" class="{{ $field }}" required>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.rfq_wizard.unit') }} *</label>
                            <select name="unit" class="{{ $field }}" required>
                                @foreach (['m3', 'ton', 'pcs', 'container'] as $u)
                                    <option value="{{ $u }}" @selected(old('unit') === $u)>{{ \App\Enums\RfqUnit::from($u)->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <input type="text" name="grade" value="{{ old('grade') }}" class="{{ $field }}" placeholder="{{ __('messages.rfq_wizard.grade_optional_ph') }}">
                    <input type="text" name="dimensions" value="{{ old('dimensions') }}" class="{{ $field }}" placeholder="{{ __('messages.rfq_wizard.dimensions_optional_ph') }}">
                </div>
            </fieldset>

            <fieldset class="space-y-4">
                <legend class="font-display text-xl font-semibold text-forest-900 dark:text-sand-100">{{ __('messages.rfq_wizard.your_details') }}</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <input type="text" name="buyer_name" value="{{ old('buyer_name') }}" class="{{ $field }}" placeholder="{{ __('messages.rfq_wizard.your_name') }} *" required>
                    <input type="email" name="buyer_email" value="{{ old('buyer_email') }}" class="{{ $field }}" placeholder="{{ __('messages.rfq_wizard.email') }} *" required>
                    <input type="text" name="buyer_company" value="{{ old('buyer_company') }}" class="{{ $field }}" placeholder="{{ __('messages.rfq_wizard.company_optional') }}">
                    <input type="text" name="buyer_phone" value="{{ old('buyer_phone') }}" class="{{ $field }}" placeholder="{{ __('messages.rfq_wizard.phone_create_ph') }}">
                    <input type="text" name="buyer_country_code" value="{{ old('buyer_country_code') }}" maxlength="2" class="{{ $field }}" placeholder="{{ __('messages.rfq_wizard.your_country_create_ph') }}" required>
                    <input type="text" name="destination_country_code" value="{{ old('destination_country_code') }}" maxlength="2" class="{{ $field }}" placeholder="{{ __('messages.rfq_wizard.destination_country') }} (2-letter) *" required>
                    <select name="incoterm" class="{{ $field }}">
                        <option value="">{{ __('messages.rfq_wizard.incoterm') }} ({{ __('messages.rfq_wizard.field_optional') }})</option>
                        @foreach (['EXW', 'FOB', 'CFR', 'CIF', 'DAP'] as $i)
                            <option value="{{ $i }}" @selected(old('incoterm') === $i)>{{ $i }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="shipping_port" value="{{ old('shipping_port') }}" class="{{ $field }}" placeholder="{{ __('messages.rfq_wizard.preferred_port_optional_ph') }}">
                    <div class="grid grid-cols-2 gap-3">
                        <input type="number" step="0.01" name="target_amount" value="{{ old('target_amount') }}" class="{{ $field }}" placeholder="{{ __('messages.rfq_wizard.target_price') }}">
                        <select name="target_currency" class="{{ $field }}">
                            <option value="">{{ __('messages.rfq_wizard.currency') }}</option>
                            @foreach (['XAF', 'USD', 'EUR', 'GBP', 'CNY'] as $c)
                                <option value="{{ $c }}" @selected(old('target_currency') === $c)>{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>
                    <input type="date" name="deadline" value="{{ old('deadline') }}" class="{{ $field }}">
                </div>
                <textarea name="notes" rows="4" class="{{ $field }}" placeholder="{{ __('messages.rfq_wizard.notes_create_ph') }}" required>{{ old('notes', $prefillNotes) }}</textarea>
            </fieldset>

            <label class="flex items-start gap-2 text-sm text-ink-soft dark:text-[#b3ab9b]">
                <input type="checkbox" name="consent" value="1" class="mt-1 rounded border-sand-300 dark:border-[#3a352e]" required>
                <span>{{ __('messages.rfq_wizard.consent_create') }}</span>
            </label>

            <button type="submit" class="rounded-full bg-forest-700 px-7 py-3.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                {{ __('messages.rfq_wizard.send_request') }}
            </button>
        </form>
    </section>
</x-layouts.app>
