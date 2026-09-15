@props([
    'variant' => 'grey',
])

@php
    $variantClasses = match ($variant) {
        'ia' => 'bg-tag-ia-bg text-tag-ia-fg',
        'ok' => 'bg-tag-ok-bg text-tag-ok-fg',
        'warn' => 'bg-tag-warn-bg text-tag-warn-fg',
        'esc' => 'bg-tag-esc-bg text-tag-esc-fg',
        default => 'bg-tag-grey-bg text-tag-grey-fg',
    };
@endphp

<span {{ $attributes->class("inline-flex items-center rounded-pill px-[9px] py-[3px] text-[11px] leading-[1.45] font-semibold whitespace-nowrap {$variantClasses}") }}>{{ $slot }}</span>
