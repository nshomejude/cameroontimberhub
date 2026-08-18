@props([
    /** Current step slug, or null on the end states. */
    'current' => null,
    /** RfqWizard instance, or null when every listed step is already done. */
    'wizard' => null,
    /** Extra trailing states shown on the end screens, e.g. ['sent' => ['Submitted', 'Awaiting confirmation']]. */
    'extra' => [],
    /** Slug of the trailing state that is currently active. */
    'activeExtra' => null,
])

@php
    use App\Services\RfqWizard;

    $steps = collect(RfqWizard::STEPS)->map(fn ($meta, $slug) => [
        'slug' => $slug,
        'label' => $meta[0],
        'caption' => $meta[1],
    ])->values();

    foreach ($extra as $slug => $meta) {
        $steps->push(['slug' => $slug, 'label' => $meta[0], 'caption' => $meta[1]]);
    }

    $currentIndex = $activeExtra
        ? $steps->search(fn ($s) => $s['slug'] === $activeExtra)
        : ($current ? $steps->search(fn ($s) => $s['slug'] === $current) : $steps->count());
@endphp

<nav aria-label="Request progress" class="rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] px-4 py-4 sm:px-6 sm:py-5">
    <ol class="flex gap-2 overflow-x-auto pb-1 sm:gap-0 sm:overflow-visible">
        @foreach ($steps as $i => $s)
            @php
                $isCurrent = $i === $currentIndex;
                $isDone = $i < $currentIndex;
                $reachable = ! $isCurrent && $isDone && $wizard && RfqWizard::isStep($s['slug']);
                $state = $isCurrent ? 'Current' : ($isDone ? 'Completed' : 'Upcoming');
            @endphp
            <li class="flex min-w-0 shrink-0 items-center sm:flex-1 sm:shrink" @if ($isCurrent) aria-current="step" @endif>
                <span class="flex min-w-0 items-center gap-2.5">
                    <span aria-hidden="true"
                          @class([
                              'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[0.8125rem] font-bold ring-1',
                              'bg-forest-700 text-white ring-forest-700' => $isCurrent,
                              'bg-white text-forest-700 ring-forest-300 dark:bg-transparent' => $isDone,
                              'bg-sand-100 text-ink-soft ring-sand-300 dark:bg-[#26241e] dark:text-[#8f887b] dark:ring-[#3a352e]' => ! $isCurrent && ! $isDone,
                          ])>
                        @if ($isDone)
                            <x-heroicon-m-check class="h-4 w-4" />
                        @else
                            {{ $i + 1 }}
                        @endif
                    </span>
                    <span class="min-w-0">
                        @if ($reachable)
                            <a href="{{ route('rfq.step', ['step' => $s['slug']]) }}"
                               class="block truncate text-[0.8125rem] font-semibold text-ink hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:text-[#e4ddcf] dark:hover:text-forest-300">
                                {{ $s['label'] }}<span class="sr-only"> — {{ $state }}. Go back to this step.</span>
                            </a>
                        @else
                            <span @class([
                                'block truncate text-[0.8125rem] font-semibold',
                                'text-forest-800 dark:text-forest-300' => $isCurrent,
                                'text-ink dark:text-[#e4ddcf]' => $isDone,
                                'text-ink-soft dark:text-[#8f887b]' => ! $isCurrent && ! $isDone,
                            ])>
                                {{ $s['label'] }}<span class="sr-only"> — {{ $state }}</span>
                            </span>
                        @endif
                        <span class="block truncate text-[0.6875rem] text-ink-soft dark:text-[#8f887b]">
                            {{ $isDone ? 'Completed' : $s['caption'] }}
                        </span>
                    </span>
                </span>
                @if (! $loop->last)
                    <span aria-hidden="true" @class([
                        'mx-3 hidden h-px flex-1 sm:block',
                        'bg-forest-300' => $isDone,
                        'bg-sand-300 dark:bg-[#3a352e]' => ! $isDone,
                    ])></span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
