{{--
    First-run dashboard.

    A brand-new buyer has no RFQs, so every tile, chart and list on the full
    dashboard would read zero. Rather than render a wall of zeroes, this screen
    does the one useful thing: it explains the flow and points at the two places
    a buyer can actually start — the RFQ wizard and the marketplace.
--}}
<x-layouts.account
    title="Dashboard"
    heading="Dashboard"
    :subheading="'Welcome, '.$user->name">

    <section class="overflow-hidden rounded-2xl bg-[#032719] px-6 py-10 text-center lg:px-12 lg:py-14">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 text-timber-200">
            <x-heroicon-o-document-plus class="h-7 w-7" />
        </span>
        <h2 class="mx-auto mt-5 max-w-xl font-display text-2xl font-bold text-white lg:text-3xl">
            Let's get your first quotes moving
        </h2>
        <p class="mx-auto mt-3 max-w-xl text-[1.125rem] leading-relaxed text-sand-300">
            Post a request for quotation and we route it to verified Cameroonian suppliers who handle
            the species, form and volume you need. Their responses land right here.
        </p>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ route('rfq.create') }}"
               class="inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 text-[1.125rem] font-bold text-forest-800 transition hover:bg-sand-200">
                Post an RFQ <x-heroicon-m-arrow-right class="h-4 w-4" />
            </a>
            <a href="{{ url('/marketplace') }}"
               class="inline-flex items-center gap-2 rounded-full border border-white/25 px-6 py-3 text-[1.125rem] font-semibold text-white transition hover:bg-white/10">
                Browse the marketplace
            </a>
        </div>
    </section>

    {{-- How it works — descriptive only, no numbers, so nothing here can be wrong. --}}
    <section aria-label="How buying works" class="mt-4 grid gap-3 lg:mt-6 lg:grid-cols-3 lg:gap-6">
        @foreach ([
            ['icon' => 'document-text', 'step' => 'Step 1', 'title' => 'Describe what you need', 'body' => 'Species, form, grade, volume, destination port and incoterm. The wizard saves each step as you go.'],
            ['icon' => 'tag', 'step' => 'Step 2', 'title' => 'Compare real quotes', 'body' => 'Verified suppliers respond with line-item pricing, lead time and validity. Compare them side by side.'],
            ['icon' => 'clipboard-document-check', 'step' => 'Step 3', 'title' => 'Award and get a receipt', 'body' => 'Awarding a quote creates an order with a fixed snapshot of the terms and a verifiable receipt.'],
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
        <x-account.panel title="Quick actions">
            <x-account.quick-actions />
        </x-account.panel>
    </div>

    <p class="mt-4 rounded-2xl border border-sand-200 bg-white px-5 py-4 text-[1.0625rem] leading-relaxed text-ink-soft">
        Submitted a request before you had an account? Requests made with
        <strong class="font-semibold text-ink">{{ $user->email }}</strong> were linked to this account when you registered.
        Anything sent from a different address stays reachable only through the secure link in its confirmation email —
        we will never attach a request to an address you have not proven you own.
    </p>
</x-layouts.account>
