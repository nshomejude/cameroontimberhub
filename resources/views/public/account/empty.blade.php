{{--
    First-run dashboard.

    A brand-new buyer has no RFQs, so every tile, chart and list on the full
    dashboard would read zero. Rather than render a wall of zeroes, this screen
    does the one useful thing: it explains the flow and points at the two places
    a buyer can actually start — the RFQ wizard and the marketplace.
--}}
<x-layouts.account
    :title="__('messages.account.dashboard')"
    :heading="__('messages.account.dashboard')"
    :subheading="__('messages.account.welcome', ['name' => $user->name])">

    <section class="overflow-hidden rounded-2xl bg-[#032719] px-6 py-10 text-center lg:px-12 lg:py-14">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 text-timber-200">
            <x-heroicon-o-document-plus class="h-7 w-7" />
        </span>
        <h2 class="mx-auto mt-5 max-w-xl font-display text-2xl font-bold text-white lg:text-3xl">
            {{ __('messages.account.empty_hero_title') }}
        </h2>
        <p class="mx-auto mt-3 max-w-xl text-[1.125rem] leading-relaxed text-sand-300">
            {{ __('messages.account.empty_hero_body') }}
        </p>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ route('rfq.create') }}"
               class="inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 text-[1.125rem] font-bold text-forest-800 transition hover:bg-sand-200">
                {{ __('messages.account.post_an_rfq') }} <x-heroicon-m-arrow-right class="h-4 w-4" />
            </a>
            <a href="{{ url('/marketplace') }}"
               class="inline-flex items-center gap-2 rounded-full border border-white/25 px-6 py-3 text-[1.125rem] font-semibold text-white transition hover:bg-white/10">
                {{ __('messages.account.empty_browse_marketplace') }}
            </a>
        </div>
    </section>

    {{-- How it works — descriptive only, no numbers, so nothing here can be wrong. --}}
    <section aria-label="{{ __('messages.account.how_buying_works') }}" class="mt-4 grid gap-3 lg:mt-6 lg:grid-cols-3 lg:gap-6">
        @foreach ([
            ['icon' => 'document-text', 'step' => __('messages.account.step_n', ['n' => 1]), 'title' => __('messages.account.empty_step_1_title'), 'body' => __('messages.account.empty_step_1_body')],
            ['icon' => 'tag', 'step' => __('messages.account.step_n', ['n' => 2]), 'title' => __('messages.account.empty_step_2_title'), 'body' => __('messages.account.empty_step_2_body')],
            ['icon' => 'clipboard-document-check', 'step' => __('messages.account.step_n', ['n' => 3]), 'title' => __('messages.account.empty_step_3_title'), 'body' => __('messages.account.empty_step_3_body')],
        ] as $card)
            <div class="rounded-2xl border border-sand-200 bg-white p-5">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-forest-50 text-forest-700">
                    <x-dynamic-component :component="'heroicon-o-'.$card['icon']" class="h-5 w-5" />
                </span>
                <p class="mt-4 text-[0.875rem] font-bold uppercase tracking-[0.14em] text-timber-700">{{ $card['step'] }}</p>
                <h3 class="mt-1 font-display text-[1.0625rem] font-bold text-forest-950">{{ $card['title'] }}</h3>
                <p class="mt-2 text-[1.0625rem] leading-relaxed text-ink-soft">{{ $card['body'] }}</p>
            </div>
        @endforeach
    </section>

    <div class="mt-4 lg:mt-6">
        <x-account.panel :title="__('messages.account.quick_actions')">
            <x-account.quick-actions />
        </x-account.panel>
    </div>

    <p class="mt-4 rounded-2xl border border-sand-200 bg-white px-5 py-4 text-[1.0625rem] leading-relaxed text-ink-soft">
        {!! __('messages.account.empty_linked_note', ['email' => '<strong class="font-semibold text-ink">'.e($user->email).'</strong>']) !!}
    </p>
</x-layouts.account>
