<x-layouts.app>
    {{-- Hero --}}
    <section class="bg-gradient-to-b from-amber-50 to-stone-50">
        <div class="mx-auto max-w-6xl px-4 py-20 text-center">
            <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">{{ __('messages.home.hero_eyebrow') }}</p>
            <h1 class="mx-auto mt-4 max-w-3xl text-4xl font-bold tracking-tight text-stone-900 sm:text-5xl">
                {{ __('messages.home.hero_title') }}
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg text-stone-600">
                {{ __('messages.home.hero_subtitle') }}
            </p>
            <div class="mt-8 flex items-center justify-center gap-4">
                <a href="{{ route('directory') }}" class="rounded-md bg-amber-600 px-6 py-3 text-sm font-semibold text-white hover:bg-amber-700">
                    {{ __('messages.home.cta_find') }}
                </a>
                <a href="#" class="rounded-md border border-stone-300 bg-white px-6 py-3 text-sm font-semibold text-stone-700 hover:border-amber-600 hover:text-amber-700">
                    {{ __('messages.home.cta_quote') }}
                </a>
            </div>
        </div>
    </section>

    {{-- Trust pillars --}}
    <section class="mx-auto max-w-6xl px-4 py-16">
        <h2 class="text-center text-2xl font-bold text-stone-900">{{ __('messages.home.pillars_title') }}</h2>
        <div class="mt-10 grid gap-6 md:grid-cols-3">
            @foreach ([
                ['title' => 'home.pillar_directory_title', 'body' => 'home.pillar_directory_body'],
                ['title' => 'home.pillar_compliance_title', 'body' => 'home.pillar_compliance_body'],
                ['title' => 'home.pillar_rfq_title', 'body' => 'home.pillar_rfq_body'],
            ] as $pillar)
                <div class="rounded-lg border border-stone-200 bg-white p-6">
                    <h3 class="text-lg font-semibold text-stone-900">{{ __('messages.' . $pillar['title']) }}</h3>
                    <p class="mt-2 text-sm text-stone-600">{{ __('messages.' . $pillar['body']) }}</p>
                </div>
            @endforeach
        </div>
    </section>
</x-layouts.app>
