@php
    $swatch = $species->swatch();

    $classification = array_filter([
        'Commercial category' => $species->commercial_category?->label(),
        'Botanical family' => $species->family,
        'Density (air-dry)' => $species->densityRange(),
        'Durability class' => $species->durability_class,
        'Janka hardness' => $species->janka_hardness ? number_format($species->janka_hardness).' N' : null,
        'Trade names' => is_array($species->trade_names) && $species->trade_names ? implode(', ', $species->trade_names) : null,
        'Local names' => is_array($species->local_names) && $species->local_names ? implode(', ', $species->local_names) : null,
        'Regions harvested' => is_array($species->region_availability) && $species->region_availability ? implode(', ', $species->region_availability) : null,
    ]);

    $sectionTitle = 'text-[1.25rem] font-bold tracking-tight text-ink';
    $panel = 'rounded-xl border border-sand-300/70 bg-white px-4 py-3';
@endphp

<x-layouts.app
    :title="$species->common_name . ' timber from Cameroon'"
    :description="$species->meta_description ?: Str::limit(strip_tags($species->description ?? ('Verified Cameroon exporters of ' . $species->common_name)), 160)"
    :breadcrumbs="[
        ['label' => 'Home', 'url' => route('home')],
        ['label' => 'Timber Species', 'url' => route('species.index')],
        ['label' => $species->common_name, 'url' => route('species.show', $species->slug)],
    ]"
    :schema="$schema">

    <div class="bg-white">
        <div class="mx-auto max-w-[1400px] px-4 py-6 lg:px-6">

            {{-- Breadcrumb --}}
            <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-2 text-[0.9375rem] text-ink-soft">
                    <li><a href="{{ route('home') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('species.index') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Timber Species</a></li>
                    <li aria-hidden="true">/</li>
                    <li><span aria-current="page" class="font-medium text-ink">{{ $species->common_name }}</span></li>
                </ol>
            </nav>

            {{-- Hero: swatch + identity --}}
            <div class="mt-4 grid gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
                <div>
                    @if ($swatch['image'])
                        <img src="{{ $swatch['image'] }}" alt="{{ $species->common_name }} timber grain"
                             width="640" height="640" class="aspect-square w-full rounded-xl object-cover">
                    @else
                        {{-- No photograph on file for this species: a generated
                             stand-in tinted with its own recorded heartwood
                             colour, never another species' photograph. --}}
                        <div class="aspect-square w-full rounded-xl" aria-hidden="true"
                             style="background-image:
                                    repeating-linear-gradient(97deg, rgba(0,0,0,.10) 0 2px, rgba(255,255,255,.05) 2px 7px, rgba(0,0,0,0) 7px 15px),
                                    linear-gradient(160deg, {{ $swatch['from'] }} 0%, {{ $swatch['via'] }} 52%, {{ $swatch['to'] }} 100%);"></div>
                        <p class="mt-2 text-[0.875rem] text-ink-soft">Illustrative swatch — no photograph on file for this species yet.</p>
                    @endif
                </div>

                <div class="min-w-0">
                    <h1 class="text-[2rem] font-bold leading-tight tracking-tight text-ink">{{ $species->common_name }}</h1>
                    @if ($species->scientific_name)
                        <p class="mt-1 text-[1rem] italic text-ink-soft">{{ $species->scientific_name }}</p>
                    @endif

                    <ul class="mt-3 flex flex-wrap gap-2">
                        @if ($species->commercial_category)
                            <li class="rounded-md bg-forest-50 px-2.5 py-1 text-[0.9375rem] font-semibold text-forest-800">{{ $species->commercial_category->shortLabel() }}</li>
                        @endif
                        @if ($species->isPremium())
                            <li class="rounded-md bg-forest-800 px-2.5 py-1 text-[0.9375rem] font-semibold text-white">Premium</li>
                        @endif
                        @if ($species->is_promoted)
                            <li class="rounded-md bg-sand-200 px-2.5 py-1 text-[0.9375rem] font-semibold text-ink">Promoted species</li>
                        @endif
                        @if ($species->is_cites_listed)
                            <li class="rounded-md bg-timber-100 px-2.5 py-1 text-[0.9375rem] font-semibold text-timber-800">
                                CITES listed{{ $species->cites_appendix ? ' — Appendix '.$species->cites_appendix : '' }}
                            </li>
                        @endif
                    </ul>

                    @if ($species->description)
                        <div class="mt-4 whitespace-pre-line text-[1.125rem] leading-relaxed text-ink-soft">{{ $species->description }}</div>
                    @endif

                    <div class="mt-6 flex flex-wrap gap-3">
                        <a href="{{ route('rfq.create', ['species' => $species->slug]) }}"
                           class="rounded-lg bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                            Request a quote
                        </a>
                        <a href="{{ route('species.index') }}"
                           class="rounded-lg border border-sand-300 px-5 py-2.5 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-600 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
                            Back to directory
                        </a>
                    </div>
                </div>
            </div>

            {{-- Detail grid --}}
            <div class="mt-10 grid gap-10 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div class="space-y-10">

                    @if ($classification)
                        <section aria-labelledby="classification-heading">
                            <h2 id="classification-heading" class="{{ $sectionTitle }}">Classification</h2>
                            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                                @foreach ($classification as $label => $value)
                                    <div class="{{ $panel }}">
                                        <dt class="text-[0.875rem] font-semibold uppercase tracking-wide text-ink-soft">{{ $label }}</dt>
                                        <dd class="mt-0.5 text-[1.0625rem] text-ink">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            @if ($species->commercial_category)
                                <p class="mt-3 text-[0.9375rem] text-ink-soft">
                                    Commercial category is a market grouping used in the timber trade. It is not a regulatory or legal classification.
                                </p>
                            @endif
                        </section>
                    @endif

                    <section aria-labelledby="eudr-heading">
                        <h2 id="eudr-heading" class="{{ $sectionTitle }}">EUDR risk assessment</h2>
                        <div class="mt-4 {{ $panel }}">
                            @if ($species->eudr_risk_note)
                                <p class="text-[1.0625rem] text-ink">{{ $species->eudr_risk_note }}</p>
                            @else
                                <p class="text-[1.0625rem] text-ink-soft">
                                    EU Deforestation Regulation risk for {{ $species->common_name }} is
                                    not yet assessed on this platform — consult current EU Deforestation
                                    Regulation guidance directly.
                                </p>
                            @endif
                        </div>
                    </section>

                    @if (is_array($species->characteristics) && count($species->characteristics))
                        <section aria-labelledby="properties-heading">
                            <h2 id="properties-heading" class="{{ $sectionTitle }}">Properties</h2>
                            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                                @foreach ($species->characteristics as $key => $value)
                                    <div class="{{ $panel }}">
                                        <dt class="text-[0.875rem] font-semibold uppercase tracking-wide text-ink-soft">{{ Str::headline((string) $key) }}</dt>
                                        <dd class="mt-0.5 text-[1.0625rem] text-ink">{{ is_array($value) ? implode(', ', $value) : $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>
                    @endif

                    @if (is_array($species->typical_uses) && count($species->typical_uses))
                        <section aria-labelledby="uses-heading">
                            <h2 id="uses-heading" class="{{ $sectionTitle }}">Typical uses</h2>
                            <ul class="mt-4 flex flex-wrap gap-2">
                                @foreach ($species->typical_uses as $use)
                                    <li class="rounded-full border border-sand-300 bg-white px-3.5 py-1.5 text-[1.0625rem] text-ink">{{ $use }}</li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if ($species->is_cites_listed)
                        <section aria-labelledby="cites-heading">
                            <h2 id="cites-heading" class="{{ $sectionTitle }}">CITES status</h2>
                            <div class="mt-4 rounded-xl border border-timber-200 bg-timber-50 px-4 py-3 text-[1.0625rem] text-ink">
                                <p>
                                    <span class="font-semibold">{{ $species->scientific_name ?: $species->common_name }}</span>
                                    is listed on CITES{{ $species->cites_appendix ? ' Appendix '.$species->cites_appendix : '' }}.
                                </p>
                                <p class="mt-1 text-ink-soft">
                                    Listings and their scope change over time. Confirm the current status and any documentation
                                    requirements with CITES and the relevant national authorities before trading.
                                </p>
                            </div>
                        </section>
                    @endif
                </div>

                <aside class="space-y-4">
                    <div class="relative overflow-hidden rounded-xl bg-forest-800 px-5 py-5 text-white">
                        <x-heroicon-o-lifebuoy class="pointer-events-none absolute -right-4 -top-4 h-28 w-28 text-white/10" aria-hidden="true" />
                        <h2 class="text-[1rem] font-bold">Need {{ $species->common_name }}?</h2>
                        <p class="mt-1 text-[1.0625rem] text-forest-100">Request a quote and we'll connect you with verified exporters.</p>
                        <a href="{{ route('rfq.create', ['species' => $species->slug]) }}"
                           class="mt-4 inline-flex rounded-lg bg-white px-4 py-2.5 text-[1.0625rem] font-semibold text-forest-800 transition hover:bg-forest-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-forest-800">
                            Request a quote
                        </a>
                    </div>

                    <div class="rounded-xl border border-sand-300/70 bg-white px-5 py-4">
                        <h2 class="text-[1.125rem] font-bold text-ink">At a glance</h2>
                        <dl class="mt-3 space-y-2 text-[1.0625rem]">
                            @foreach (array_slice($classification, 0, 5, true) as $label => $value)
                                <div class="flex gap-3">
                                    <dt class="w-32 shrink-0 text-ink-soft">{{ $label }}</dt>
                                    <dd class="min-w-0 flex-1 font-medium text-ink">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </aside>
            </div>

            {{-- Exporters --}}
            <section class="mt-12 pb-16" aria-labelledby="exporters-heading">
                <h2 id="exporters-heading" class="{{ $sectionTitle }}">Verified exporters handling {{ $species->common_name }}</h2>

                @if ($companies->isNotEmpty())
                    <div class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($companies as $company)
                            <x-supplier-card :company="$company" />
                        @endforeach
                    </div>
                @else
                    <div class="mt-5 rounded-xl border border-dashed border-sand-300 bg-white p-10 text-center">
                        <p class="text-[1.0625rem] text-ink-soft">We're onboarding verified exporters for {{ $species->common_name }}.</p>
                        <a href="{{ route('rfq.create', ['species' => $species->slug]) }}"
                           class="mt-4 inline-flex rounded-lg bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                            Submit an inquiry
                        </a>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-layouts.app>
