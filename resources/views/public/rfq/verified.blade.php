<x-layouts.app title="Request confirmed">
    <section class="mx-auto max-w-xl px-4 py-24 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-forest-50 text-forest-600">
            <x-heroicon-o-check-circle class="h-7 w-7" />
        </span>
        <h1 class="mt-6 font-display text-3xl font-semibold text-forest-950">Request confirmed</h1>
        <p class="mt-3 text-ink-soft">Your request <strong class="text-forest-800">{{ $rfq->reference_code }}</strong> is confirmed. Our team will match it to verified exporters. Quote this reference in any follow-up email.</p>
        <a href="{{ route('directory') }}" class="mt-8 inline-flex items-center gap-2 rounded-full bg-forest-700 px-6 py-3 text-sm font-semibold text-white transition hover:bg-forest-800">
            Browse exporters <x-heroicon-m-arrow-right class="h-4 w-4" />
        </a>
    </section>
</x-layouts.app>
