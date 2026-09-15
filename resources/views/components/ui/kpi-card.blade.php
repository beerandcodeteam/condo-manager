@props([
    'label',
    'value',
    'delta' => null,
    'deltaColor' => 'neutral',
])

@php
    $deltaClass = match ($deltaColor) {
        'ok' => 'text-tag-ok-fg',
        'warn' => 'text-tag-warn-fg',
        'esc' => 'text-tag-esc-fg',
        'accent' => 'text-accent',
        'neutral' => 'text-ink-secondary',
        default => null,
    };
@endphp

<div {{ $attributes->class('rounded-card border border-line bg-white px-5 py-[18px] shadow-card') }}>
    <div class="text-[12px] font-medium text-ink-secondary">{{ $label }}</div>
    <div class="mt-1.5 text-[30px] leading-[1.1] font-semibold tracking-[-0.02em] tabular-nums">{{ $value }}</div>

    @if (filled($delta))
        <div
            @class(['mt-1.5 text-[12px]', $deltaClass])
            @if ($deltaClass === null) style="color: {{ $deltaColor }}" @endif
        >{{ $delta }}</div>
    @endif
</div>
