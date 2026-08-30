@php
    $featureLabels = [
        'verified_badge' => 'Verification badge',
        'leads_receive' => 'Receive RFQ leads',
        'featured' => 'Featured placement',
        'api' => 'API access',
    ];
@endphp
<x-layouts.app
    title="Pricing"
    description="Cameroon Timber Hub plans — for domestic buyers, suppliers, dealers, exporters, international buyers, verification, compliance and market intelligence.">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-6xl px-4 py-14 text-center">
            <p class="eyebrow">Pricing</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">Choose how you work with us</h1>
            <p class="mx-auto mt-3 max-w-2xl text-ink-soft dark:text-[#b3ab9b]">
                Free core discovery and education for everyone. Choose the section below that matches what you're doing —
                pricing is shown up front, no hidden fees.
            </p>
        </div>
    </section>

    {{-- Entry-choice nav — mirrors docs/PRICING_SPEC.md §24's segmentation --}}
    <nav aria-label="Pricing sections" class="sticky top-[var(--header-h,64px)] z-10 border-b border-sand-200 bg-white/95 backdrop-blur dark:border-[#2c2a24] dark:bg-[#14130f]/95">
        <div class="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-4 py-2 text-sm">
            @foreach ($segments as $segment)
                <a href="#{{ $segment['id'] }}" class="whitespace-nowrap rounded-full px-3.5 py-1.5 font-medium text-ink-soft transition hover:bg-forest-50 hover:text-forest-800 dark:text-[#b3ab9b] dark:hover:bg-forest-950 dark:hover:text-forest-300">
                    {{ $segment['label'] }}
                </a>
            @endforeach
        </div>
    </nav>

    <div class="mx-auto max-w-6xl px-4">
        @foreach ($segments as $segment)
            <section id="{{ $segment['id'] }}" class="scroll-mt-28 border-b border-sand-200 py-14 last:border-b-0 dark:border-[#2c2a24]">
                <p class="eyebrow">{{ $segment['eyebrow'] }}</p>
                <h2 class="mt-2 font-display text-2xl font-semibold text-forest-950 dark:text-sand-100 sm:text-3xl">{{ $segment['label'] }}</h2>
                <p class="mt-2 max-w-2xl text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">{{ $segment['intro'] }}</p>

                <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @php($dbPlans = match ($segment['id']) {
                        'sell' => $supplierPlans,
                        'buy' => $buyerPlans,
                        'deal' => $dealerPlans,
                        'export' => $exporterPlans,
                        'buy-international' => $buyInternationalPlans,
                        'verify-comply' => $verifyComplyPlans,
                        'analyze' => $analyzePlans,
                        'learn' => $learnPlans,
                        default => collect(),
                    })
                    {{-- Live, DB-backed tiers (segment column on `plans`) --}}
                    @foreach ($dbPlans as $plan)
                        @php($bullets = $plan->features['bullets'] ?? null)
                        <div @class([
                            'flex flex-col rounded-2xl border bg-white dark:bg-[#1f1d18] p-6 shadow-sm',
                            'border-forest-300 ring-1 ring-forest-200' => $plan->slug === 'professional',
                            'border-sand-200 dark:border-[#2c2a24]' => $plan->slug !== 'professional',
                        ])>
                            @if ($plan->slug === 'professional')
                                <span class="mb-3 inline-block self-start rounded-full bg-forest-700 px-3 py-0.5 text-xs font-semibold text-white">Most popular</span>
                            @endif
                            <h3 class="font-display text-lg font-semibold text-forest-900 dark:text-sand-100">{{ $plan->name }}</h3>
                            <p class="mt-1.5 text-[0.8125rem] text-ink-soft dark:text-[#b3ab9b]">{{ $plan->description }}</p>
                            <p class="mt-4">
                                <span class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">{{ number_format((float) $plan->price_amount) }}</span>
                                <span class="text-[0.8125rem] text-ink-soft dark:text-[#b3ab9b]">{{ $plan->price_currency }} / {{ $plan->billing_period }}</span>
                            </p>
                            <ul class="mt-5 space-y-2 text-[0.8125rem] text-ink-soft dark:text-[#b3ab9b]">
                                @if ($bullets !== null)
                                    {{-- Flat feature bullet list — no boolean feature gates behind these plans yet --}}
                                    @foreach ($bullets as $bullet)
                                        <li class="flex items-start gap-2"><x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-forest-500" />{{ $bullet }}</li>
                                    @endforeach
                                @else
                                    <li class="flex items-start gap-2"><x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-forest-500" />Up to {{ (int) $plan->feature('max_gallery', 3) }} gallery images</li>
                                    @foreach ($featureLabels as $key => $label)
                                        @if ($plan->feature($key))
                                            <li class="flex items-start gap-2"><x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-forest-500" />{{ $label }}</li>
                                        @endif
                                    @endforeach
                                @endif
                            </ul>
                            @if (in_array($segment['id'], ['sell', 'buy'], true))
                                <a href="{{ route('register') }}" class="mt-6 inline-flex items-center justify-center gap-1.5 rounded-full bg-forest-700 px-4 py-2 text-[0.8125rem] font-semibold text-white transition hover:bg-forest-800">
                                    List your company
                                </a>
                            @else
                                <a href="{{ route('contact') }}" class="mt-6 inline-flex items-center justify-center gap-1.5 rounded-full border border-forest-300 px-4 py-2 text-[0.8125rem] font-semibold text-forest-700 transition hover:bg-forest-50 dark:border-forest-700 dark:text-forest-300 dark:hover:bg-forest-950">
                                    Get in touch
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <section class="border-t border-sand-200 bg-sand-50 py-10 dark:border-[#2c2a24] dark:bg-[#14130f]">
        <div class="mx-auto max-w-3xl px-4 text-center">
            <p class="text-[0.8125rem] text-ink-soft dark:text-[#8f887b]">
                Prices shown are proposed Cameroon Timber Hub launch list prices and are not statements of statutory fees,
                government charges, taxes, certification fees, payment-provider charges, freight rates, or third-party
                provider pricing unless expressly stated. Verification fees purchase a review process — approval depends
                on the evidence provided, not on payment alone. Documents are reviewed by Cameroon Timber Hub based on
                information submitted by companies; buyers should conduct final due diligence before any transaction.
            </p>
        </div>
    </section>
</x-layouts.app>
