@props([
    'rows' => 4,
    'invalid' => null,
])

@php
    $fieldName = $attributes->get('name') ?? ($attributes->wire('model')->value() ?: null);
    $hasError = $invalid ?? ($fieldName !== null && isset($errors) && $errors->has($fieldName));
@endphp

<textarea
    rows="{{ $rows }}"
    @if ($hasError) aria-invalid="true" @endif
    {{ $attributes->class([
        'w-full resize-y rounded-control border bg-surface-input px-3 py-2 text-[13px] leading-[1.45] text-ink placeholder:text-ink-tertiary transition-shadow outline-none focus:border-accent focus:ring-[3px] focus:ring-accent/20 disabled:cursor-not-allowed disabled:opacity-60',
        'border-line-strong' => ! $hasError,
        'border-tag-esc-fg' => $hasError,
    ]) }}
>{{ $slot }}</textarea>
