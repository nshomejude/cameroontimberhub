@props([
    'name',
    'label',
    'type' => 'text',
    'icon' => null,
    'placeholder' => null,
    'value' => null,
    'required' => false,
    'autocomplete' => null,
    'autofocus' => false,
    'options' => null,
    'help' => null,
    'toggle' => false,
    'maxlength' => null,
])

@php
    $id = 'auth-'.trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
    $error = $errors->first($name);
    // Passwords are never echoed back — only non-secret fields keep old input.
    $isSecret = $type === 'password';
    $current = $isSecret ? null : old($name, $value);
    $described = collect([
        $help ? $id.'-help' : null,
        $error ? $id.'-error' : null,
    ])->filter()->implode(' ');

    $shell = 'flex items-center gap-2 rounded-xl border bg-white px-3.5 transition focus-within:ring-2 '
        .($error
            ? 'border-red-400 focus-within:border-red-500 focus-within:ring-red-100'
            : 'border-sand-300 focus-within:border-forest-500 focus-within:ring-forest-100');

    $control = 'w-full border-0 bg-transparent py-3 text-[1.125rem] text-ink placeholder:text-ink-soft/60 focus:outline-none focus:ring-0';
@endphp

<div {{ $attributes->class(['space-y-1.5']) }}>
    <label for="{{ $id }}" class="block text-[1.0625rem] font-semibold text-ink">
        {{ $label }}
        @if ($required)
            <span aria-hidden="true" class="text-red-600">*</span><span class="sr-only">(required)</span>
        @endif
    </label>

    <div class="{{ $shell }}" @if ($toggle) x-data="{ show: false }" @endif>
        @if ($icon)
            <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-5 w-5 shrink-0 text-timber-500" aria-hidden="true" />
        @endif

        @if ($type === 'select')
            <select id="{{ $id }}" name="{{ $name }}" class="{{ $control }} appearance-none pr-1"
                    @if ($required) required @endif
                    @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
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
            <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-ink-soft" aria-hidden="true" />
        @else
            <input id="{{ $id }}" name="{{ $name }}" class="{{ $control }}"
                   @if ($toggle) :type="show ? 'text' : 'password'" @endif
                   type="{{ $type }}"
                   @unless ($isSecret) value="{{ $current }}" @endunless
                   @if ($required) required @endif
                   @if ($placeholder) placeholder="{{ $placeholder }}" @endif
                   @if ($maxlength) maxlength="{{ $maxlength }}" @endif
                   @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
                   @if ($described) aria-describedby="{{ $described }}" @endif
                   @if ($error) aria-invalid="true" @endif
                   @if ($autofocus) autofocus @endif>

            @if ($toggle)
                <button type="button" @click="show = !show" :aria-pressed="show ? 'true' : 'false'" aria-pressed="false"
                        class="shrink-0 rounded-md p-1 text-ink-soft transition hover:text-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-600">
                    <span class="sr-only" x-text="show ? 'Hide password' : 'Show password'">Show password</span>
                    <x-heroicon-o-eye class="h-5 w-5" x-show="! show" aria-hidden="true" />
                    <x-heroicon-o-eye-slash class="h-5 w-5" x-show="show" x-cloak aria-hidden="true" />
                </button>
            @endif
        @endif
    </div>

    @if ($help)
        <p id="{{ $id }}-help" class="text-[0.9375rem] leading-snug text-ink-soft">{{ $help }}</p>
    @endif

    @if ($error)
        <p id="{{ $id }}-error" class="flex items-start gap-1.5 text-[0.9375rem] font-medium text-red-700">
            <x-heroicon-m-exclamation-circle class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
