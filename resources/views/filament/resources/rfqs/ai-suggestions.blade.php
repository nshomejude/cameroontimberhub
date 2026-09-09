<div class="space-y-4">
    @if ($suggestions->isEmpty())
        <p class="text-sm text-gray-500">No candidate suppliers found for this RFQ's species/verification criteria.</p>
    @else
        <p class="text-xs text-gray-500">
            Advisory only — this does not route the RFQ. Review, then use "Route to companies" to actually route it.
        </p>
        <ol class="space-y-3">
            @foreach ($suggestions as $i => $s)
                <li class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <span class="font-medium">{{ $i + 1 }}. {{ $s['company']->legal_name }}</span>
                        @if ($s['score'] !== null)
                            <span class="text-xs font-semibold text-primary-600">Score: {{ $s['score'] }}</span>
                        @endif
                    </div>
                    @if ($s['why'])
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $s['why'] }}</p>
                    @else
                        <p class="mt-1 text-sm text-gray-400 italic">No AI ranking available — deterministic candidate order.</p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
