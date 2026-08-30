@php
    // Public checkpoint tracking (gap-plan 1.5.11).
    //
    // Everything rendered below comes from CheckpointTracker::publicPayload()
    // — a hand-written allow-list. The CheckpointUpdate/trackable models are
    // deliberately NOT passed to this view, so no internal identity, disk
    // path or recording user can leak through a relation in Blade.
@endphp

<x-layouts.app
    title="Track a shipment"
    description="Check the checkpoint history for a Cameroon Timber Hub shipment."
    noindex>

    <div class="mx-auto max-w-3xl px-4 py-10 sm:py-14">
        <h1 class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100 sm:text-3xl">Shipment tracking</h1>

        @if (empty($checkpoints))
            <p class="mt-4 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                No tracking information was found for this link.
            </p>
        @else
            <ol class="mt-6 space-y-4">
                @foreach ($checkpoints as $checkpoint)
                    <li class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <div class="font-display font-semibold text-forest-950 dark:text-sand-100">{{ $checkpoint['status'] }}</div>
                        @if ($checkpoint['location'])
                            <div class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">{{ $checkpoint['location'] }}</div>
                        @endif
                        @if ($checkpoint['notes'])
                            <div class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">{{ $checkpoint['notes'] }}</div>
                        @endif
                        @if ($checkpoint['has_photo'])
                            <div class="mt-1 text-xs text-ink-soft dark:text-[#8f887b]">Photo attached</div>
                        @endif
                        <div class="mt-1 text-xs text-ink-soft dark:text-[#8f887b]">{{ $checkpoint['recorded_at']->format('d M Y, H:i') }}</div>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</x-layouts.app>
