@php
    use App\Enums\RfqIncoterm;
    use App\Enums\RfqUnit;
    use App\Enums\TimberForm;

    $details = $wizard->step('details');
    $delivery = $wizard->step('delivery');
    $contact = $wizard->step('contact');
    $items = $wizard->items();

    $cell = 'px-3 py-2.5 text-[1.0625rem] text-ink dark:text-[#e4ddcf]';
    $head = 'px-3 py-2 text-left text-[0.875rem] font-bold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]';
@endphp

<div class="space-y-7">
    <p class="text-[1.0625rem] text-ink-soft dark:text-[#b3ab9b]">
        {{ __('messages.rfq_wizard.review_intro') }}
    </p>

    {{-- RFQ information --}}
    <section aria-labelledby="review-details" class="rounded-xl border border-sand-200 dark:border-[#3a352e]">
        <div class="flex items-baseline justify-between gap-3 border-b border-sand-200 px-4 py-3 dark:border-[#3a352e]">
            <h3 id="review-details" class="text-[1.125rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.rfq_information') }}</h3>
            <a href="{{ route('rfq.step', ['step' => 'details']) }}" class="rounded text-[1.0625rem] font-semibold text-forest-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:text-forest-300">{{ __('messages.rfq_wizard.edit') }}<span class="sr-only"> {{ __('messages.rfq_wizard.rfq_information') }}</span></a>
        </div>
        <dl class="grid gap-4 px-4 py-4 sm:grid-cols-3">
            <div>
                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.rfq_title') }}</dt>
                <dd class="mt-0.5 text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">{{ $details['title'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.project_name') }}</dt>
                <dd class="mt-0.5 text-[1.0625rem] text-ink dark:text-[#e4ddcf]">{{ ($details['project_name'] ?? null) ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.response_deadline') }}</dt>
                <dd class="mt-0.5 text-[1.0625rem] text-ink dark:text-[#e4ddcf]">
                    {{ ! empty($details['deadline']) ? \Illuminate\Support\Carbon::parse($details['deadline'])->isoFormat('D MMM YYYY') : '—' }}
                </dd>
            </div>
        </dl>
    </section>

    {{-- Products --}}
    <section aria-labelledby="review-products" class="rounded-xl border border-sand-200 dark:border-[#3a352e]">
        <div class="flex items-baseline justify-between gap-3 border-b border-sand-200 px-4 py-3 dark:border-[#3a352e]">
            <h3 id="review-products" class="text-[1.125rem] font-bold text-forest-950 dark:text-sand-100">
                {{ __('messages.rfq_wizard.products_and_requirements') }}
                <span class="ml-1 font-normal text-ink-soft dark:text-[#8f887b]">({{ trans_choice('messages.rfq_wizard.product_count', count($items), ['count' => count($items)]) }})</span>
            </h3>
            <a href="{{ route('rfq.step', ['step' => 'products']) }}" class="rounded text-[1.0625rem] font-semibold text-forest-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:text-forest-300">{{ __('messages.rfq_wizard.edit') }}<span class="sr-only"> {{ __('messages.rfq_wizard.products_label') }}</span></a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[36rem] border-collapse">
                <caption class="sr-only">{{ __('messages.rfq_wizard.products_and_requirements') }}</caption>
                <thead class="border-b border-sand-200 dark:border-[#3a352e]">
                    <tr>
                        <th scope="col" class="{{ $head }}">{{ __('messages.rfq_wizard.col_hash') }}</th>
                        <th scope="col" class="{{ $head }}">{{ __('messages.rfq_wizard.col_species') }}</th>
                        <th scope="col" class="{{ $head }}">{{ __('messages.rfq_wizard.col_form') }}</th>
                        <th scope="col" class="{{ $head }}">{{ __('messages.rfq_wizard.col_specifications') }}</th>
                        <th scope="col" class="{{ $head }}">{{ __('messages.rfq_wizard.col_quantity') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand-200 dark:divide-[#3a352e]">
                    @foreach ($items as $i => $item)
                        <tr>
                            <td class="{{ $cell }} text-ink-soft">{{ $i + 1 }}</td>
                            <th scope="row" class="{{ $cell }} text-left font-semibold">
                                {{ ($item['species_id'] ?? null) ? ($speciesById[$item['species_id']]->common_name ?? __('messages.rfq_wizard.species_word')) : ($item['species_text'] ?? __('messages.rfq_wizard.timber_word')) }}
                            </th>
                            <td class="{{ $cell }}">{{ TimberForm::tryFrom($item['form'] ?? '')?->label() ?? '—' }}</td>
                            <td class="{{ $cell }}">
                                {{ collect([$item['dimensions'] ?? null, $item['grade'] ?? null, $item['moisture_content'] ?? null])->filter()->implode(', ') ?: '—' }}
                            </td>
                            <td class="{{ $cell }} whitespace-nowrap">
                                {{ rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, '.', ' '), '0'), '.') }}
                                {{ RfqUnit::tryFrom($item['unit'] ?? '')?->label() ?? $item['unit'] ?? '' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- Delivery & terms --}}
    <section aria-labelledby="review-terms" class="rounded-xl border border-sand-200 dark:border-[#3a352e]">
        <div class="flex items-baseline justify-between gap-3 border-b border-sand-200 px-4 py-3 dark:border-[#3a352e]">
            <h3 id="review-terms" class="text-[1.125rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.delivery_and_terms') }}</h3>
            <a href="{{ route('rfq.step', ['step' => 'delivery']) }}" class="rounded text-[1.0625rem] font-semibold text-forest-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:text-forest-300">{{ __('messages.rfq_wizard.edit') }}<span class="sr-only"> {{ __('messages.rfq_wizard.delivery_and_terms') }}</span></a>
        </div>
        <dl class="grid gap-4 px-4 py-4 sm:grid-cols-4">
            <div>
                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.destination') }}</dt>
                <dd class="mt-0.5 text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">{{ strtoupper($delivery['destination_country_code'] ?? '—') }}</dd>
            </div>
            <div>
                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.port_delivery_point') }}</dt>
                <dd class="mt-0.5 text-[1.0625rem] text-ink dark:text-[#e4ddcf]">{{ ($delivery['shipping_port'] ?? null) ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.delivery_terms_summary') }}</dt>
                <dd class="mt-0.5 text-[1.0625rem] text-ink dark:text-[#e4ddcf]">
                    {{ ! empty($delivery['incoterm']) ? RfqIncoterm::tryFrom($delivery['incoterm'])?->label() : '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.target_price') }}</dt>
                <dd class="mt-0.5 text-[1.0625rem] text-ink dark:text-[#e4ddcf]">
                    {{ ! empty($delivery['target_amount']) ? number_format((float) $delivery['target_amount'], 2).' '.($delivery['target_currency'] ?? '') : '—' }}
                </dd>
            </div>
        </dl>
        <div class="border-t border-sand-200 px-4 py-4 dark:border-[#3a352e]">
            <h4 class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.requirement_details') }}</h4>
            <p class="mt-1 whitespace-pre-line text-[1.0625rem] leading-relaxed text-ink dark:text-[#e4ddcf]">{{ $delivery['notes'] ?? '' }}</p>
        </div>
    </section>

    {{-- Contact --}}
    <section aria-labelledby="review-contact" class="rounded-xl border border-sand-200 dark:border-[#3a352e]">
        <div class="flex items-baseline justify-between gap-3 border-b border-sand-200 px-4 py-3 dark:border-[#3a352e]">
            <h3 id="review-contact" class="text-[1.125rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.your_details_heading') }}</h3>
            <a href="{{ route('rfq.step', ['step' => 'contact']) }}" class="rounded text-[1.0625rem] font-semibold text-forest-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:text-forest-300">{{ __('messages.rfq_wizard.edit') }}<span class="sr-only"> {{ __('messages.rfq_wizard.your_details_heading') }}</span></a>
        </div>
        <dl class="grid gap-4 px-4 py-4 sm:grid-cols-4">
            <div>
                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.name') }}</dt>
                <dd class="mt-0.5 text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">{{ $contact['buyer_name'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.email') }}</dt>
                <dd class="mt-0.5 break-all text-[1.0625rem] text-ink dark:text-[#e4ddcf]">{{ $contact['buyer_email'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.company') }}</dt>
                <dd class="mt-0.5 text-[1.0625rem] text-ink dark:text-[#e4ddcf]">{{ ($contact['buyer_company'] ?? null) ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.country') }}</dt>
                <dd class="mt-0.5 text-[1.0625rem] text-ink dark:text-[#e4ddcf]">{{ strtoupper($contact['buyer_country_code'] ?? '—') }}</dd>
            </div>
        </dl>
    </section>

    {{-- What actually happens next. Deliberately literal: no supplier sees this
         request until the buyer confirms their address and our team approves. --}}
    <section aria-labelledby="review-next" class="rounded-xl bg-sand-100 px-4 py-4 dark:bg-[#26241e]">
        <h3 id="review-next" class="text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.what_happens_next') }}</h3>
        <ol class="mt-2 space-y-1.5 text-[1.0625rem] text-ink-soft dark:text-[#b3ab9b]">
            <li>1. {!! __('messages.rfq_wizard.next_1', ['email' => '<strong class="text-ink dark:text-[#e4ddcf]">'.e($contact['buyer_email'] ?? __('messages.rfq_wizard.next_1_fallback')).'</strong>']) !!}</li>
            <li>2. {{ __('messages.rfq_wizard.next_2') }}</li>
            <li>3. {{ __('messages.rfq_wizard.next_3') }}</li>
        </ol>
    </section>
</div>
