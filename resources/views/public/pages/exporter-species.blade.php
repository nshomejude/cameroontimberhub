<x-layouts.app
    :title="$species->common_name . ' exporters from Cameroon — verified suppliers'"
    :description="'Find verified Cameroon ' . $species->common_name . ' timber exporters. ' . ($species->meta_description ?: 'Connect directly with PEFC/FSC-tracked suppliers and request a quote.')"
    :schema="$schema">

    <section class="relative overflow-hidden border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-br from-forest-800 to-forest-950 text-sand-100">
        <x-brand-mark class="pointer-events-none absolute -right-16 -top-16 h-80 w-80 opacity-10" />
        <div class="relative mx-auto max-w-6xl px-4 py-12">
            <nav class="mb-5 flex items-center gap-1.5 text-sm text-forest-200">
                <a href="{{ route('directory') }}" class="transition hover:text-white">Exporters</a>
                <x-heroicon-m-chevron-right class="h-4 w-4" />
                <span class="text-sand-100">{{ $species->common_name }}</span>
            </nav>
            <h1 class="font-display text-4xl font-semibold text-white sm:text-5xl">
                Verified {{ $species->common_name }} exporters from Cameroon
            </h1>
            <p class="mt-4 max-w-2xl text-lg text-forest-200">
                Connect directly with verified Cameroonian timber companies that supply {{ $species->common_name }}
                @if($species->scientific_name)<span class="italic">({{ $species->scientific_name }})</span>@endif.
                All listed companies have passed Cameroon Timber Hub's document review process.
            </p>
            <div class="mt-7 flex flex-wrap gap-4">
                <a href="{{ route('rfq.create', ['species' => $species->slug]) }}" class="inline-flex items-center gap-1.5 rounded-full bg-timber-400 px-5 py-2.5 text-sm font-semibold text-forest-950 transition hover:bg-timber-300">
                    Request a quote <x-heroicon-m-arrow-right class="h-4 w-4" />
                </a>
                <a href="{{ route('species.show', $species->slug) }}" class="inline-flex items-center gap-1.5 rounded-full border border-forest-400/40 px-5 py-2.5 text-sm font-semibold text-forest-100 transition hover:bg-forest-700">
                    About {{ $species->common_name }}
                </a>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-12">
        <p class="text-sm text-ink-soft dark:text-[#b3ab9b]">
            {{ $companies->total() }} verified {{ Str::plural('exporter', $companies->total()) }} of {{ $species->common_name }} found
        </p>

        <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($companies as $company)
                @include('public.partials.company-card', ['company' => $company])
            @endforeach
        </div>

        @if($companies->hasPages())
            <div class="mt-8">{{ $companies->links() }}</div>
        @endif

        <div class="mt-12 rounded-2xl bg-forest-50 dark:bg-forest-950 border border-forest-100 dark:border-forest-900 p-8">
            <h2 class="font-display text-xl font-semibold text-forest-950 dark:text-sand-100">Don't see what you need?</h2>
            <p class="mt-2 text-ink-soft dark:text-[#b3ab9b]">Submit an RFQ and our team will match you with the right verified exporter — including companies not yet listed.</p>
            <a href="{{ route('rfq.create', ['species' => $species->slug]) }}" class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                Submit a request <x-heroicon-m-arrow-right class="h-4 w-4" />
            </a>
        </div>

        <p class="mt-8 text-xs text-ink-soft dark:text-[#b3ab9b]">
            Documents are reviewed by Cameroon Timber Hub based on information submitted by companies. Buyers should conduct final due diligence before any transaction.
        </p>
    </section>
</x-layouts.app>
