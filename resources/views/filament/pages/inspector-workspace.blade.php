<x-filament-panels::page>
    @php
        $pending = $this->getPendingInspections();
        $inProgress = $this->getInProgressInspections();
        $finalised = $this->getRecentlyFinalisedInspections();
    @endphp

    <div class="space-y-6">
        {{-- Pending / scheduled inspections --}}
        <x-filament::section>
            <x-slot name="heading">Scheduled inspections</x-slot>

            @if($pending->isEmpty())
                <p class="text-sm text-ink-soft">You have no scheduled inspections right now.</p>
            @else
                <div class="divide-y divide-sand-200 -my-6">
                    @foreach($pending as $inspection)
                        <div class="flex items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink">
                                    {{ $inspection->timberLot?->lot_number ?? ('Lot #'.$inspection->timber_lot_id) }}
                                </p>
                                <p class="mt-0.5 text-xs text-ink-soft">
                                    {{ ucwords(str_replace('_', ' ', (string) $inspection->inspection_type)) }}
                                    @if($inspection->scheduled_for)
                                        &middot; Scheduled {{ $inspection->scheduled_for->isoFormat('D MMM YYYY') }}
                                    @endif
                                </p>
                            </div>
                            <x-filament::badge color="warning">Scheduled</x-filament::badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- In-progress inspections --}}
        <x-filament::section>
            <x-slot name="heading">In-progress inspections</x-slot>

            @if($inProgress->isEmpty())
                <p class="text-sm text-ink-soft">You have no inspections in progress.</p>
            @else
                <div class="divide-y divide-sand-200 -my-6">
                    @foreach($inProgress as $inspection)
                        <div class="flex items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink">
                                    {{ $inspection->timberLot?->lot_number ?? ('Lot #'.$inspection->timber_lot_id) }}
                                </p>
                                <p class="mt-0.5 text-xs text-ink-soft">
                                    Performed {{ $inspection->performed_at?->isoFormat('D MMM YYYY') }}
                                </p>
                            </div>
                            <x-filament::badge color="info">Awaiting finalisation</x-filament::badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Recently finalised --}}
        <x-filament::section>
            <x-slot name="heading">Recently finalised reports</x-slot>

            @if($finalised->isEmpty())
                <p class="text-sm text-ink-soft">You have not finalised any inspection reports yet.</p>
            @else
                <div class="divide-y divide-sand-200 -my-6">
                    @foreach($finalised as $inspection)
                        <div class="flex items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink">
                                    {{ $inspection->timberLot?->lot_number ?? ('Lot #'.$inspection->timber_lot_id) }}
                                </p>
                                <p class="mt-0.5 text-xs text-ink-soft">
                                    Finalised {{ $inspection->finalised_at?->isoFormat('D MMM YYYY') }}
                                </p>
                            </div>
                            <x-filament::badge :color="$inspection->result === 'pass' ? 'success' : ($inspection->result === 'fail' ? 'danger' : 'warning')">
                                {{ ucfirst((string) $inspection->result) }}
                            </x-filament::badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
