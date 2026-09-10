<x-layouts.app :title="__('messages.billing.needs_company_title')" noindex>
    <div class="mx-auto max-w-lg px-4 py-16 text-center">
        <h1 class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.billing.needs_company_title') }}</h1>
        <p class="mt-3 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.billing.needs_company_body', ['plan' => $plan->name]) }}</p>
        <a href="{{ route('register') }}" class="mt-6 inline-flex rounded-full bg-forest-700 px-5 py-2.5 text-[0.875rem] font-semibold text-white transition hover:bg-forest-800">
            {{ __('messages.billing.create_company') }}
        </a>
    </div>
</x-layouts.app>
