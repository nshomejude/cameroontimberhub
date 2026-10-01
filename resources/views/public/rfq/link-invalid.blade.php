{{-- Rendered (HTTP 403) when an rfq.verify / inquiry.verify link is expired,
     tampered with or otherwise invalid — a recovery path, not a dead end. --}}
<x-layouts.app :title="__('messages.rfq_followup.link_invalid_title')" noindex>
    <section class="mx-auto max-w-2xl px-4 py-20">
        <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-sand-100 text-ink-soft dark:bg-[#26241e] dark:text-[#b3ab9b]">
            <x-heroicon-o-clock class="h-7 w-7" />
        </span>
        <h1 class="mt-6 font-display text-3xl font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_followup.link_invalid_title') }}</h1>

        @if (($kind ?? 'rfq') === 'inquiry')
            <p class="mt-3 text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.rfq_followup.link_invalid_inquiry_body') }}</p>
            <div class="mt-8 flex flex-wrap gap-3">
                @if (! empty($company?->slug))
                    <a href="{{ route('companies.show', $company->slug) }}" class="inline-flex items-center gap-2 rounded-full bg-forest-700 px-6 py-3 text-sm font-semibold text-white transition hover:bg-forest-800">
                        {{ __('messages.rfq_followup.back_to_supplier', ['company' => $company->name]) }}
                    </a>
                @endif
                <a href="{{ route('directory') }}" class="inline-flex items-center gap-2 rounded-full border border-sand-300 px-6 py-3 text-sm font-semibold text-ink dark:border-[#3a352e] dark:text-[#e4ddcf]">
                    {{ __('messages.rfq_followup.browse_exporters') }}
                </a>
            </div>
        @else
            <p class="mt-3 text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.rfq_followup.link_invalid_body') }}</p>
            <h2 class="mt-8 font-semibold text-ink dark:text-[#e4ddcf]">{{ __('messages.rfq_followup.resend_title') }}</h2>
            @include('public.rfq.partials.resend-form')
        @endif
    </section>
</x-layouts.app>
