<x-filament-panels::page>
    @php
        $checklist = $this->getChecklist();
        $done      = $this->getCompletedCount();
        $total     = $this->getTotalCount();
        $pct       = $total > 0 ? (int) round($done / $total * 100) : 0;
    @endphp

    <div class="space-y-6">
        {{-- Progress bar --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Overall progress</span>
                <span class="text-sm font-bold text-amber-600 dark:text-amber-400">{{ $done }} / {{ $total }}</span>
            </div>
            <div class="h-2.5 w-full rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                <div class="h-full rounded-full bg-amber-500 transition-all duration-500"
                     style="width: {{ $pct }}%"></div>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                @if($done === $total)
                    All steps complete — your profile is ready for verification review.
                @else
                    Complete all steps to be eligible for a verified badge.
                @endif
            </p>
        </div>

        {{-- Checklist items --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden">
            @foreach($checklist as $i => $step)
                <div class="flex items-start gap-4 px-5 py-4">
                    <div class="mt-0.5 flex-none">
                        @if($step['done'])
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/40">
                                <x-heroicon-m-check class="h-4 w-4 text-green-600 dark:text-green-400" />
                            </span>
                        @else
                            <span class="flex h-6 w-6 items-center justify-center rounded-full border-2 border-gray-300 dark:border-gray-600 text-xs font-bold text-gray-400">
                                {{ $i + 1 }}
                            </span>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold {{ $step['done'] ? 'text-green-700 dark:text-green-400 line-through decoration-green-400' : 'text-gray-900 dark:text-white' }}">
                            {{ $step['label'] }}
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</p>
                    </div>
                    @if(!$step['done'] && $step['url'])
                        <a href="{{ $step['url'] }}"
                           class="flex-none rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 px-3 py-1.5 text-xs font-semibold text-amber-700 dark:text-amber-400 hover:bg-amber-100 dark:hover:bg-amber-900/50 transition">
                            Fix →
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
