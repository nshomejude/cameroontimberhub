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
    description="Cameroon Timber Hub plans — from a free listing to verified exporter profiles with RFQ leads and featured placement.">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-6xl px-4 py-14 text-center">
            <p class="eyebrow">Plans</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">Choose how you grow</h1>
            <p class="mx-auto mt-3 max-w-2xl text-ink-soft dark:text-[#b3ab9b]">List for free, or upgrade to a verified profile with buyer leads. Plans are assigned by our team — no card required to get started.</p>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14">
        <div class="grid gap-6 md:grid-cols-3">
            @foreach ($plans as $plan)
                <div @class([
                    'flex flex-col rounded-2xl border bg-white dark:bg-[#1f1d18] p-7 shadow-sm',
                    'border-forest-300 ring-1 ring-forest-200' => $plan->slug === 'professional',
                    'border-sand-200 dark:border-[#2c2a24]' => $plan->slug !== 'professional',
                ])>
                    @if ($plan->slug === 'professional')
                        <span class="mb-3 inline-block self-start rounded-full bg-forest-700 px-3 py-0.5 text-xs font-semibold text-white">Most popular</span>
                    @endif
                    <h2 class="font-display text-2xl font-semibold text-forest-900 dark:text-sand-100">{{ $plan->name }}</h2>
                    <p class="mt-2 text-sm text-ink-soft dark:text-[#b3ab9b]">{{ $plan->description }}</p>
                    <p class="mt-5">
                        <span class="font-display text-3xl font-semibold text-forest-950 dark:text-sand-100">{{ number_format((float) $plan->price_amount) }}</span>
                        <span class="text-sm text-ink-soft dark:text-[#b3ab9b]">{{ $plan->price_currency }} / {{ $plan->billing_period }}</span>
                    </p>
                    <ul class="mt-6 space-y-2.5 text-sm text-ink-soft dark:text-[#b3ab9b]">
                        <li class="flex items-center gap-2"><x-heroicon-m-check-circle class="h-5 w-5 text-forest-500" />Up to {{ (int) $plan->feature('max_gallery', 3) }} gallery images</li>
                        @foreach ($featureLabels as $key => $label)
                            @if ($plan->feature($key))
                                <li class="flex items-center gap-2"><x-heroicon-m-check-circle class="h-5 w-5 text-forest-500" />{{ $label }}</li>
                            @endif
                        @endforeach
                    </ul>
                    <a href="#" class="mt-7 inline-flex items-center justify-center gap-1.5 rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                        List your company
                    </a>
                </div>
            @endforeach
        </div>
        <p class="mt-8 text-center text-sm text-ink-soft dark:text-[#b3ab9b]">Documents are reviewed by Cameroon Timber Hub based on information submitted by companies. Buyers should conduct final due diligence before any transaction.</p>
    </section>
</x-layouts.app>
