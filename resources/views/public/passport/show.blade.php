<x-layouts.app
    :title="'CTH Timber Passport — '.$lot->lot_number"
    :description="'Public traceability passport for timber lot '.$lot->lot_number.', verified via Cameroon Timber Hub.'"
    :breadcrumbs="$breadcrumbs">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-5xl px-4 py-12">
            <p class="eyebrow">CTH Timber Passport</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">{{ $lot->lot_number }}</h1>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <span class="inline-flex items-center rounded-full bg-forest-100 px-3 py-1 text-sm font-semibold text-forest-800">
                    {{ $lot->status->label() }}
                </span>
                @if ($lot->species)
                    <span class="flex items-center gap-1 text-sm text-ink-soft dark:text-[#b3ab9b]">
                        <x-heroicon-s-tag class="h-4 w-4 shrink-0 text-forest-600" />
                        <a href="{{ route('species.show', $lot->species) }}" class="hover:underline">{{ $lot->species->common_name }}</a>
                    </span>
                @endif
            </div>
        </div>
    </section>

    <div class="mx-auto max-w-5xl px-4 py-10">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">

            <div class="lg:col-span-2 space-y-6">
                <div class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="text-[1.0625rem] font-bold text-ink dark:text-sand-100">Lot details</h2>
                    <dl class="mt-4 divide-y divide-sand-200 dark:divide-[#2c2a24]">
                        <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                            <dt class="text-ink-soft dark:text-[#b3ab9b]">Lot ID</dt>
                            <dd class="font-semibold text-ink dark:text-sand-100">{{ $lot->lot_number }}</dd>
                        </div>
                        @if ($lot->product_form)
                            <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">Product form</dt>
                                <dd class="font-semibold text-ink dark:text-sand-100">{{ ucwords(str_replace('_', ' ', $lot->product_form)) }}</dd>
                            </div>
                        @endif
                        @if ($lot->volume_m3 !== null)
                            <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">Volume</dt>
                                <dd class="font-semibold text-ink dark:text-sand-100">{{ number_format((float) $lot->volume_m3, 3) }} m³</dd>
                            </div>
                        @endif
                        <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                            <dt class="text-ink-soft dark:text-[#b3ab9b]">Country of origin</dt>
                            <dd class="font-semibold text-ink dark:text-sand-100">{{ $lot->origin_country }}</dd>
                        </div>
                        @if ($lot->origin_region)
                            <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">Geographic origin</dt>
                                <dd class="font-semibold text-ink dark:text-sand-100">{{ $lot->origin_region }}</dd>
                            </div>
                        @elseif ($lot->origin_latitude !== null && $lot->origin_longitude !== null)
                            <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">Geographic origin (approx.)</dt>
                                <dd class="font-semibold text-ink dark:text-sand-100">{{ number_format((float) $lot->origin_latitude, 1) }}, {{ number_format((float) $lot->origin_longitude, 1) }}</dd>
                            </div>
                        @endif
                        @if ($lot->harvest_period_start || $lot->harvest_period_end)
                            <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">Harvest period</dt>
                                <dd class="font-semibold text-ink dark:text-sand-100">
                                    {{ $lot->harvest_period_start?->format('M Y') }}@if($lot->harvest_period_start && $lot->harvest_period_end) – @endif{{ $lot->harvest_period_end?->format('M Y') }}
                                </dd>
                            </div>
                        @endif
                        @if ($lot->processing_site)
                            <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">Processing site</dt>
                                <dd class="font-semibold text-ink dark:text-sand-100">{{ $lot->processing_site }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>

                <div class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="text-[1.0625rem] font-bold text-ink dark:text-sand-100">Compliance status</h2>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span class="inline-flex items-center rounded-full bg-sand-100 px-3 py-1 text-sm font-semibold text-ink dark:bg-[#2c2a24] dark:text-sand-100">
                            Legality: {{ $legalityLabel }}
                        </span>
                        <span class="inline-flex items-center rounded-full bg-sand-100 px-3 py-1 text-sm font-semibold text-ink dark:bg-[#2c2a24] dark:text-sand-100">
                            Traceability: {{ $traceabilityLabel }}
                        </span>
                        <span class="inline-flex items-center rounded-full bg-sand-100 px-3 py-1 text-sm font-semibold text-ink dark:bg-[#2c2a24] dark:text-sand-100">
                            Inspection: {{ $inspectionLabel }}
                        </span>
                    </div>
                </div>

                @if ($events)
                    <div class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <h2 class="text-[1.0625rem] font-bold text-ink dark:text-sand-100">Traceability events</h2>
                        <ul class="mt-4 space-y-3">
                            @foreach ($events as $event)
                                <li class="flex items-start justify-between gap-4 border-b border-sand-100 pb-3 text-[0.9375rem] last:border-0 last:pb-0 dark:border-[#2c2a24]">
                                    <span class="font-semibold text-ink dark:text-sand-100">
                                        {{ $event->event_type instanceof \BackedEnum ? ucwords(str_replace('_', ' ', $event->event_type->value)) : ucwords(str_replace('_', ' ', (string) $event->event_type)) }}
                                    </span>
                                    <span class="text-ink-soft dark:text-[#b3ab9b]">{{ $event->occurred_at?->format('d M Y') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($transformations)
                    <div class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <h2 class="text-[1.0625rem] font-bold text-ink dark:text-sand-100">Processing history</h2>
                        <ul class="mt-4 space-y-3">
                            @foreach ($transformations as $transformation)
                                <li class="flex items-start justify-between gap-4 border-b border-sand-100 pb-3 text-[0.9375rem] last:border-0 last:pb-0 dark:border-[#2c2a24]">
                                    <span class="font-semibold text-ink dark:text-sand-100">
                                        {{ ucwords(str_replace('_', ' ', (string) $transformation->transformation_type)) }}
                                    </span>
                                    <span class="text-ink-soft dark:text-[#b3ab9b]">{{ $transformation->processed_at?->format('d M Y') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <div class="lg:col-span-1">
                @if ($company)
                    <h2 class="mb-3 text-[1.0625rem] font-bold text-ink dark:text-sand-100">Supplied by</h2>
                    <x-supplier-card :company="$company" compact />
                @endif
            </div>
        </div>
    </div>

</x-layouts.app>
