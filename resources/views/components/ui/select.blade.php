@props([
    'placeholder' => null,
    'invalid' => null,
])

@php
    $fieldName = $attributes->get('name') ?? ($attributes->wire('model')->value() ?: null);
    $hasError = $invalid ?? ($fieldName !== null && isset($errors) && $errors->has($fieldName));
@endphp

<div class="relative">
    <select
        @if ($hasError) aria-invalid="true" @endif
        {{ $attributes->class([
            'w-full appearance-none rounded-control border bg-surface-input py-2 pr-8 pl-3 text-[13px] leading-[1.45] text-ink transition-shadow outline-none focus:border-accent focus:ring-[3px] focus:ring-accent/20 disabled:cursor-not-allowed disabled:opacity-60',
            'border-line-strong' => ! $hasError,
            'border-tag-esc-fg' => $hasError,
        ]) }}
    >
        @if (filled($placeholder))
            <option value="">{{ $placeholder }}</option>
        @endif

        {{ $slot }}
    </select>

    <span class="pointer-events-none absolute inset-y-0 right-3 grid place-items-center text-[10px] text-ink-tertiary" aria-hidden="true">▾</span>
</div>
