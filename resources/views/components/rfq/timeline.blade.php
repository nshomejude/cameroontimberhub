@props([
    /** list<array{state:'done'|'current'|'todo', title:string, body:string, when:?string}> */
    'steps' => [],
    'heading' => 'RFQ activity',
])

<section {{ $attributes->class(['rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18]']) }}
         aria-labelledby="rfq-timeline-heading">
    <div class="border-b border-sand-200 px-5 py-4 dark:border-[#2c2a24]">
        <h2 id="rfq-timeline-heading" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ $heading }}</h2>
    </div>

    <ol class="px-5 py-5">
        @foreach ($steps as $s)
            <li class="relative flex gap-4 pb-6 last:pb-0">
                @unless ($loop->last)
                    <span aria-hidden="true" @class([
                        'absolute left-[0.9375rem] top-8 h-[calc(100%-1.5rem)] w-px',
                        'bg-forest-300' => $s['state'] === 'done',
                        'bg-sand-300 dark:bg-[#3a352e]' => $s['state'] !== 'done',
                    ])></span>
                @endunless

                <span aria-hidden="true" @class([
                    'relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-1',
                    'bg-forest-700 text-white ring-forest-700' => $s['state'] === 'done',
                    'bg-white text-forest-700 ring-forest-400 dark:bg-[#1f1d18] dark:text-forest-300' => $s['state'] === 'current',
                    'bg-sand-100 text-ink-soft ring-sand-300 dark:bg-[#26241e] dark:text-[#8f887b] dark:ring-[#3a352e]' => $s['state'] === 'todo',
                ])>
                    @if ($s['state'] === 'done')
                        <x-heroicon-m-check class="h-4 w-4" />
                    @elseif ($s['state'] === 'current')
                        <x-heroicon-m-clock class="h-4 w-4" />
                    @else
                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                    @endif
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                        <h3 @class([
                            'text-[0.875rem] font-bold',
                            'text-forest-800 dark:text-forest-300' => $s['state'] === 'current',
                            'text-ink dark:text-[#e4ddcf]' => $s['state'] === 'done',
                            'text-ink-soft dark:text-[#8f887b]' => $s['state'] === 'todo',
                        ])>
                            {{ $s['title'] }}
                            <span class="sr-only">— {{ ['done' => 'completed', 'current' => 'in progress', 'todo' => 'not started'][$s['state']] }}</span>
                        </h3>
                        @if (! empty($s['when']))
                            <span class="text-[0.75rem] text-ink-soft dark:text-[#8f887b]">{{ $s['when'] }}</span>
                        @endif
                    </div>
                    <p class="mt-0.5 text-[0.8125rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">{{ $s['body'] }}</p>
                </div>
            </li>
        @endforeach
    </ol>
</section>
