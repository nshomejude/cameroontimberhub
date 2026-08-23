@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'required' => false,
    'options' => null,
    'placeholder' => null,
    'help' => null,
    'rows' => 4,
    'step' => null,
    'maxlength' => null,
    'min' => null,
    'autocomplete' => null,
    'autofocus' => false,
    'inline' => false,
])

@php
    // `items[0][quantity]` is the HTML name; `items.0.quantity` is the key
    // Laravel uses for old input and validation errors.
    $dotted = str_replace(['[', ']'], ['.', ''], $name);
    $id = 'f-'.trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
    $error = $errors->first($dotted);
    $described = collect([$help ? $id.'-help' : null, $error ? $id.'-error' : null])->filter()->implode(' ');
    $current = old($dotted, $value);

    $base = 'w-full rounded-xl border bg-white dark:bg-[#1f1d18] px-3.5 py-2.5 text-[1.125rem] text-ink dark:text-[#f1ece1] placeholder:text-ink-soft/60 transition focus:outline-none focus-visible:outline-none focus:ring-2 focus:ring-forest-100 dark:focus:ring-forest-900';
    $ring = $error
        ? ' border-red-400 focus:border-red-500 focus:ring-red-100'
        : ' border-sand-300 dark:border-[#3a352e] focus:border-forest-500';
    $class = $base.$ring;
@endphp

<div {{ $attributes->class(['space-y-1.5', 'sm:col-span-2' => $inline]) }}>
    @if ($type !== 'checkbox')
        <label for="{{ $id }}" class="block text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">
            {{ $label }}
            @if ($required)
                <span aria-hidden="true" class="text-red-600">*</span><span class="sr-only">(required)</span>
            @else
                <span class="ml-1 font-normal text-ink-soft dark:text-[#8f887b]">(optional)</span>
            @endif
        </label>
    @endif

    @if ($help)
        <p id="{{ $id }}-help" class="text-[0.9375rem] leading-snug text-ink-soft dark:text-[#8f887b]">{{ $help }}</p>
    @endif

    @if ($type === 'select')
        <select id="{{ $id }}" name="{{ $name }}" class="{{ $class }}"
                @if ($required) required @endif
                @if ($described) aria-describedby="{{ $described }}" @endif
                @if ($error) aria-invalid="true" @endif
                @if ($autofocus) autofocus @endif>
            @if ($placeholder)
                <option value="">{{ $placeholder }}</option>
            @endif
            @foreach ($options ?? [] as $optValue => $optLabel)
                <option value="{{ $optValue }}" @selected((string) $current === (string) $optValue)>{{ $optLabel }}</option>
            @endforeach
        </select>
    @elseif ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" class="{{ $class }}"
                  @if ($required) required @endif
                  @if ($placeholder) placeholder="{{ $placeholder }}" @endif
                  @if ($maxlength) maxlength="{{ $maxlength }}" @endif
                  @if ($described) aria-describedby="{{ $described }}" @endif
                  @if ($error) aria-invalid="true" @endif
                  @if ($autofocus) autofocus @endif>{{ $current }}</textarea>
    @elseif ($type === 'checkbox')
        <label for="{{ $id }}" class="flex items-start gap-3 text-[1.0625rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
            <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1"
                   @checked($current)
                   @if ($required) required @endif
                   @if ($described) aria-describedby="{{ $described }}" @endif
                   @if ($error) aria-invalid="true" @endif
                   @if ($autofocus) autofocus @endif
                   class="mt-1 h-4 w-4 shrink-0 rounded border-sand-400 text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
            <span>{{ $label }}</span>
        </label>
    @else
        <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}" value="{{ $current }}" class="{{ $class }}"
               @if ($required) required @endif
               @if ($placeholder) placeholder="{{ $placeholder }}" @endif
               @if ($maxlength) maxlength="{{ $maxlength }}" @endif
               @if ($step) step="{{ $step }}" @endif
               @if ($min !== null) min="{{ $min }}" @endif
               @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
               @if ($described) aria-describedby="{{ $described }}" @endif
               @if ($error) aria-invalid="true" @endif
               @if ($autofocus) autofocus @endif>
    @endif

    @if ($error)
        <p id="{{ $id }}-error" role="alert" class="flex items-start gap-1.5 text-[0.9375rem] font-medium text-red-700 dark:text-red-400">
            <x-heroicon-m-exclamation-circle class="mt-px h-3.5 w-3.5 shrink-0" />
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
