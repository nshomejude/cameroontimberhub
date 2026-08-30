<x-filament-panels::page>
    @php
        $checklist = $this->getChecklist();
        $done      = $this->getCompletedCount();
        $total     = $this->getTotalCount();
        $pct       = $total > 0 ? (int) round($done / $total * 100) : 0;
    @endphp

    <div class="space-y-6">
        {{-- Progress --}}
        <x-filament::section>
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold text-ink">Overall progress</span>
                <span class="text-sm font-bold text-forest-700">{{ $done }} / {{ $total }}</span>
            </div>
            <div class="h-2.5 w-full rounded-full bg-sand-200 overflow-hidden">
                <div class="h-full rounded-full bg-forest-700 transition-all duration-500"
                     style="width: {{ $pct }}%"></div>
            </div>
            <p class="mt-2 text-xs text-ink-soft">
                @if($done === $total)
                    All steps complete — your profile is ready for verification review.
                @else
                    Complete all steps to be eligible for a verified badge.
                @endif
            </p>
        </x-filament::section>

        {{-- Checklist items --}}
        <x-filament::section>
            <div class="divide-y divide-sand-200 -my-6">
                @foreach($checklist as $i => $step)
                    <div class="flex items-start gap-4 py-4">
                        <div class="mt-0.5 flex-none">
                            @if($step['done'])
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-forest-100">
                                    <x-heroicon-m-check class="h-4 w-4 text-forest-700" />
                                </span>
                            @else
                                <span class="flex h-6 w-6 items-center justify-center rounded-full border-2 border-sand-300 text-xs font-bold text-ink-soft">
                                    {{ $i + 1 }}
                                </span>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold {{ $step['done'] ? 'text-forest-700 line-through decoration-forest-300' : 'text-ink' }}">
                                {{ $step['label'] }}
                            </p>
                            <p class="mt-0.5 text-xs text-ink-soft">{{ $step['detail'] }}</p>
                        </div>
                        @if(!$step['done'] && $step['url'])
                            <a href="{{ $step['url'] }}">
                                <x-filament::badge color="warning">
                                    Fix →
                                </x-filament::badge>
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
