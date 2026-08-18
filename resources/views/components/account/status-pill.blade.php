@props([
    'label',
    'color' => 'gray',
])

@php
    // Maps the enums' own `color()` names onto the design tokens, so a status
    // badge here always agrees with the same status elsewhere in the app.
    $classes = match ($color) {
        'success' => 'bg-forest-50 text-forest-800 ring-forest-200',
        'info' => 'bg-sky-50 text-sky-800 ring-sky-200',
        'warning' => 'bg-timber-50 text-timber-800 ring-timber-200',
        'danger' => 'bg-red-50 text-red-800 ring-red-200',
        default => 'bg-sand-200 text-ink-soft ring-sand-300',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full px-2.5 py-1 text-[0.6875rem] font-bold ring-1 ring-inset', $classes]) }}>
    {{ $label }}
</span>
