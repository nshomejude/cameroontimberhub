@props([
    'wizard',
    'speciesById' => null,
    'matchingSuppliers' => 0,
    /** Show "Edit" links back into the wizard (hidden once the RFQ is submitted). */
    'editable' => true,
])

@php
    use App\Enums\RfqIncoterm;
    use App\Enums\RfqUnit;
    use App\Enums\TimberForm;

    $details = $wizard->step('details');
    $delivery = $wizard->step('delivery');
    $contact = $wizard->step('contact');
    $items = $wizard->items();
    $speciesById = $speciesById ?? $wizard->speciesById();

    $label = fn (?array $item) => $item === null ? null
        : (($item['species_id'] ?? null) ? ($speciesById[$item['species_id']]->common_name ?? 'Species') : ($item['species_text'] ?? 'Timber'));
    $qty = fn (array $i) => rtrim(rtrim(number_format((float) ($i['quantity'] ?? 0), 2, '.', ' '), '0'), '.')
        .' '.(RfqUnit::tryFrom($i['unit'] ?? '')?->label() ?? $i['unit'] ?? '');
@endphp

<aside {{ $attributes->class(['rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18]']) }}
       aria-labelledby="rfq-summary-heading">
    <div class="border-b border-sand-200 dark:border-[#2c2a24] px-5 py-4">
        <h2 id="rfq-summary-heading" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">RFQ Summary</h2>
    </div>

    <div class="divide-y divide-sand-200 dark:divide-[#2c2a24] text-[0.8125rem]">
        {{-- RFQ information --}}
        <section class="px-5 py-4">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="text-[0.875rem] font-bold text-ink dark:text-[#e4ddcf]">RFQ Information</h3>
                @if ($editable && $wizard->completed('details'))
                    <a href="{{ route('rfq.step', ['step' => 'details']) }}" class="rounded text-[0.75rem] font-semibold text-forest-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:text-forest-300">Edit<span class="sr-only"> RFQ information</span></a>
                @endif
            </div>
            @if ($wizard->completed('details'))
                <dl class="mt-3 space-y-2.5">
                    <div>
                        <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">RFQ Title</dt>
                        <dd class="font-semibold text-ink dark:text-[#e4ddcf]">{{ $details['title'] ?? '—' }}</dd>
                    </div>
                    @if (! empty($details['project_name']))
                        <div>
                            <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Project Name</dt>
                            <dd class="text-ink dark:text-[#e4ddcf]">{{ $details['project_name'] }}</dd>
                        </div>
                    @endif
                    @if (! empty($details['deadline']))
                        <div>
                            <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Response Deadline</dt>
                            <dd class="text-ink dark:text-[#e4ddcf]">{{ \Illuminate\Support\Carbon::parse($details['deadline'])->isoFormat('D MMM YYYY') }}</dd>
                        </div>
                    @endif
                </dl>
            @else
                <p class="mt-2 text-ink-soft dark:text-[#8f887b]">Not filled in yet.</p>
            @endif
        </section>

        {{-- Products --}}
        <section class="px-5 py-4">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="text-[0.875rem] font-bold text-ink dark:text-[#e4ddcf]">Products Summary</h3>
                @if ($editable && $items)
                    <a href="{{ route('rfq.step', ['step' => 'products']) }}" class="rounded text-[0.75rem] font-semibold text-forest-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:text-forest-300">Edit<span class="sr-only"> products</span></a>
                @endif
            </div>
            @if ($items)
                <p class="mt-2 text-ink-soft dark:text-[#8f887b]">{{ trans_choice(':count product|:count products', count($items), ['count' => count($items)]) }}</p>
                <ul class="mt-2 space-y-1.5">
                    @foreach ($items as $item)
                        <li class="flex gap-2">
                            <span aria-hidden="true" class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-forest-400"></span>
                            <span class="min-w-0">
                                <span class="block font-semibold text-ink dark:text-[#e4ddcf]">{{ $label($item) }}
                                    <span class="font-normal text-ink-soft dark:text-[#8f887b]">({{ TimberForm::tryFrom($item['form'] ?? '')?->label() }})</span>
                                </span>
                                <span class="block text-ink-soft dark:text-[#8f887b]">{{ $qty($item) }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-ink-soft dark:text-[#8f887b]">No products added yet.</p>
            @endif
        </section>

        {{-- Terms --}}
        <section class="px-5 py-4">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="text-[0.875rem] font-bold text-ink dark:text-[#e4ddcf]">Terms Summary</h3>
                @if ($editable && $wizard->completed('delivery'))
                    <a href="{{ route('rfq.step', ['step' => 'delivery']) }}" class="rounded text-[0.75rem] font-semibold text-forest-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:text-forest-300">Edit<span class="sr-only"> terms</span></a>
                @endif
            </div>
            @if ($wizard->completed('delivery'))
                <dl class="mt-3 space-y-2.5">
                    <div>
                        <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Destination</dt>
                        <dd class="text-ink dark:text-[#e4ddcf]">{{ strtoupper($delivery['destination_country_code'] ?? '') }}{{ ! empty($delivery['shipping_port']) ? ' · '.$delivery['shipping_port'] : '' }}</dd>
                    </div>
                    @if (! empty($delivery['incoterm']))
                        <div>
                            <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Delivery Terms</dt>
                            <dd class="text-ink dark:text-[#e4ddcf]">{{ RfqIncoterm::tryFrom($delivery['incoterm'])?->label() }}</dd>
                        </div>
                    @endif
                    @if (! empty($delivery['target_amount']))
                        <div>
                            <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Target Price</dt>
                            <dd class="text-ink dark:text-[#e4ddcf]">{{ number_format((float) $delivery['target_amount'], 2) }} {{ $delivery['target_currency'] ?? '' }}</dd>
                        </div>
                    @endif
                </dl>
            @else
                <p class="mt-2 text-ink-soft dark:text-[#8f887b]">Not filled in yet.</p>
            @endif
        </section>

        {{-- Contact --}}
        @if ($wizard->completed('contact'))
            <section class="px-5 py-4">
                <div class="flex items-baseline justify-between gap-3">
                    <h3 class="text-[0.875rem] font-bold text-ink dark:text-[#e4ddcf]">Contact</h3>
                    @if ($editable)
                        <a href="{{ route('rfq.step', ['step' => 'contact']) }}" class="rounded text-[0.75rem] font-semibold text-forest-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:text-forest-300">Edit<span class="sr-only"> contact details</span></a>
                    @endif
                </div>
                <p class="mt-2 font-semibold text-ink dark:text-[#e4ddcf]">{{ $contact['buyer_name'] ?? '' }}</p>
                <p class="break-all text-ink-soft dark:text-[#8f887b]">{{ $contact['buyer_email'] ?? '' }}</p>
            </section>
        @endif

        {{-- Real supplier-match figure. Only rendered when the buyer picked
             catalogue species, because that is the only case we can count. --}}
        @if ($matchingSuppliers > 0)
            <section class="px-5 py-4">
                <h3 class="text-[0.875rem] font-bold text-ink dark:text-[#e4ddcf]">Potential matches</h3>
                <p class="mt-2 text-ink-soft dark:text-[#8f887b]">
                    <strong class="text-forest-800 dark:text-forest-300">{{ $matchingSuppliers }}</strong>
                    verified {{ Str::plural('supplier', $matchingSuppliers) }} on the Hub list the species in this request.
                    Our team decides who to route it to after you confirm your email address.
                </p>
            </section>
        @endif
    </div>

    @isset($footer)
        <div class="border-t border-sand-200 dark:border-[#2c2a24] px-5 py-4">
            {{ $footer }}
        </div>
    @endisset
</aside>
