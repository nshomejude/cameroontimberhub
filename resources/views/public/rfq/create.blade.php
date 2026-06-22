@php($field = 'w-full rounded-lg border border-sand-300 bg-white px-3 py-2.5 text-ink focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none')
<x-layouts.app
    title="Request a quote"
    description="Tell us what Cameroonian timber you need. We'll route your request to verified exporters — no account required.">

    <section class="border-b border-sand-200 bg-gradient-to-b from-forest-50 to-sand-50">
        <div class="mx-auto max-w-3xl px-4 py-12">
            <p class="eyebrow">No account needed</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950">Request a quote</h1>
            <p class="mt-3 text-ink-soft">Describe what you need; we'll route it to verified exporters. Submitting does not constitute a contract — buyers should conduct final due diligence before any transaction.</p>
        </div>
    </section>

    <section class="mx-auto max-w-3xl px-4 py-10">
        @if ($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                Please fix the highlighted fields below.
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
                <legend class="font-display text-xl font-semibold text-forest-900">What you need</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-soft">Species (from catalog)</label>
                        <select name="species_id" class="{{ $field }}">
                            <option value="">— choose or type below —</option>
                            @foreach ($species as $sp)
                                <option value="{{ $sp->id }}" @selected(old('species_id') == $sp->id || (! old('species_id') && $prefillSpecies === $sp->slug))>{{ $sp->common_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-soft">Or species (free text)</label>
                        <input type="text" name="species_text" value="{{ old('species_text') }}" class="{{ $field }}" placeholder="e.g. Sapele">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-soft">Product form *</label>
                        <select name="form" class="{{ $field }}" required>
                            @foreach (['logs', 'sawn', 'veneer', 'plywood', 'other'] as $f)
                                <option value="{{ $f }}" @selected(old('form') === $f)>{{ ucfirst($f) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink-soft">Quantity *</label>
                            <input type="number" step="0.01" name="quantity" value="{{ old('quantity') }}" class="{{ $field }}" required>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink-soft">Unit *</label>
                            <select name="unit" class="{{ $field }}" required>
                                @foreach (['m3', 'ton', 'pcs', 'container'] as $u)
                                    <option value="{{ $u }}" @selected(old('unit') === $u)>{{ $u }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <input type="text" name="grade" value="{{ old('grade') }}" class="{{ $field }}" placeholder="Grade (optional)">
                    <input type="text" name="dimensions" value="{{ old('dimensions') }}" class="{{ $field }}" placeholder="Dimensions (optional)">
                </div>
            </fieldset>

            <fieldset class="space-y-4">
                <legend class="font-display text-xl font-semibold text-forest-900">Your details</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <input type="text" name="buyer_name" value="{{ old('buyer_name') }}" class="{{ $field }}" placeholder="Your name *" required>
                    <input type="email" name="buyer_email" value="{{ old('buyer_email') }}" class="{{ $field }}" placeholder="Email *" required>
                    <input type="text" name="buyer_company" value="{{ old('buyer_company') }}" class="{{ $field }}" placeholder="Company (optional)">
                    <input type="text" name="buyer_phone" value="{{ old('buyer_phone') }}" class="{{ $field }}" placeholder="Phone e.g. +33...">
                    <input type="text" name="buyer_country_code" value="{{ old('buyer_country_code') }}" maxlength="2" class="{{ $field }}" placeholder="Your country (2-letter, e.g. FR) *" required>
                    <input type="text" name="destination_country_code" value="{{ old('destination_country_code') }}" maxlength="2" class="{{ $field }}" placeholder="Destination country (2-letter) *" required>
                    <select name="incoterm" class="{{ $field }}">
                        <option value="">Incoterm (optional)</option>
                        @foreach (['EXW', 'FOB', 'CFR', 'CIF', 'DAP'] as $i)
                            <option value="{{ $i }}" @selected(old('incoterm') === $i)>{{ $i }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="shipping_port" value="{{ old('shipping_port') }}" class="{{ $field }}" placeholder="Preferred port (optional)">
                    <div class="grid grid-cols-2 gap-3">
                        <input type="number" step="0.01" name="target_amount" value="{{ old('target_amount') }}" class="{{ $field }}" placeholder="Target price">
                        <select name="target_currency" class="{{ $field }}">
                            <option value="">Currency</option>
                            @foreach (['XAF', 'USD', 'EUR', 'GBP', 'CNY'] as $c)
                                <option value="{{ $c }}" @selected(old('target_currency') === $c)>{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>
                    <input type="date" name="deadline" value="{{ old('deadline') }}" class="{{ $field }}">
                </div>
                <textarea name="notes" rows="4" class="{{ $field }}" placeholder="Describe your requirements (20–4000 characters) *" required>{{ old('notes') }}</textarea>
            </fieldset>

            <label class="flex items-start gap-2 text-sm text-ink-soft">
                <input type="checkbox" name="consent" value="1" class="mt-1 rounded border-sand-300" required>
                <span>I consent to Cameroon Timber Hub sharing this request with verified exporters and contacting me by email.</span>
            </label>

            <button type="submit" class="rounded-full bg-forest-700 px-7 py-3.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                Send request
            </button>
        </form>
    </section>
</x-layouts.app>
