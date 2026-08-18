@props(['company', 'productCount' => 0])

@php
    $verified = $company->status === \App\Enums\CompanyStatus::Verified;

    // Real counts only — no rounded "25+" marketing numbers.
    $stats = collect([
        ['label' => 'Products', 'value' => $productCount > 0 ? number_format($productCount) : null],
        ['label' => 'Export Countries', 'value' => ($n = $company->exportMarkets->count()) > 0 ? number_format($n) : null],
        ['label' => 'Years Experience', 'value' => (int) $company->years_experience > 0 ? number_format((int) $company->years_experience) : null],
    ])->filter(fn ($s) => filled($s['value']))->values();
@endphp

<section class="mx-4 mt-5 rounded-2xl border border-sand-200 bg-white p-4" aria-labelledby="m-supplier-heading">
    <h2 id="m-supplier-heading" class="sr-only">Supplier</h2>

    <div class="flex items-start gap-3">
        <img src="{{ $company->logoUrl() }}" alt="" loading="lazy" width="128" height="128"
             class="h-16 w-16 shrink-0 rounded-full bg-white object-cover ring-1 ring-sand-200">

        <div class="min-w-0 flex-1">
            <h3 class="flex flex-wrap items-center gap-1.5 text-[1.0625rem] font-bold text-ink">
                <a href="{{ route('companies.show', $company->slug) }}"
                   class="rounded transition hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">{{ $company->name }}</a>
                @if ($verified)
                    <x-heroicon-s-check-badge class="h-5 w-5 text-forest-600" aria-hidden="true" />
                    <span class="sr-only">Verified supplier</span>
                @endif
            </h3>

            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.8125rem] text-ink-soft">
                @if ($verified)
                    <span class="inline-flex items-center gap-1 font-semibold text-forest-700">
                        <x-heroicon-o-check-badge class="h-4 w-4" aria-hidden="true" />
                        Verified Supplier
                    </span>
                @endif
                @if ($company->city)
                    @if ($verified)<span aria-hidden="true">&bull;</span>@endif
                    <span>{{ $company->city }}, Cameroon</span>
                @endif
            </p>
        </div>
    </div>

    <a href="{{ route('companies.show', $company->slug) }}"
       class="mt-4 flex w-full items-center justify-center gap-1.5 rounded-lg border border-sand-300 px-4 py-2.5 text-[0.9375rem] font-semibold text-ink transition hover:border-forest-600 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
        View Company
        <x-heroicon-m-chevron-right class="h-4 w-4" aria-hidden="true" />
    </a>

    @if ($stats->isNotEmpty())
        <dl class="mt-4 grid border-t border-sand-200 pt-3"
            style="grid-template-columns: repeat({{ $stats->count() }}, minmax(0, 1fr));">
            @foreach ($stats as $i => $stat)
                <div @class(['px-2', 'border-l border-sand-200' => $i > 0])>
                    <dd class="text-[1.125rem] font-bold leading-none text-ink">{{ $stat['value'] }}</dd>
                    <dt class="mt-1 text-[0.75rem] text-ink-soft">{{ $stat['label'] }}</dt>
                </div>
            @endforeach
        </dl>
    @endif
</section>
