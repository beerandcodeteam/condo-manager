@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'loading' => null,
])

@php
    $variantClasses = match ($variant) {
        'secondary' => 'border border-line-strong bg-white font-medium text-ink hover:bg-surface-soft',
        'ghost' => 'border border-transparent bg-transparent font-medium text-ink-secondary hover:text-ink',
        default => 'border border-transparent bg-accent font-semibold text-white hover:bg-accent-hover',
    };

    $sizeClasses = match ($size) {
        'sm' => 'rounded-control-sm px-3.5 py-[7px] text-[12px]',
        default => 'rounded-control-lg px-3.5 py-[9px] text-[13px]',
    };

    $loadingTarget = is_string($loading) ? $loading : ($attributes->wire('click')->value() ?: null);
    $hasLoadingState = $href === null && ($loading === true || filled($loadingTarget));

    $classes = "inline-flex items-center justify-center gap-2 leading-[1.45] whitespace-nowrap transition-colors disabled:cursor-not-allowed disabled:opacity-60 data-loading:opacity-70 {$variantClasses} {$sizeClasses}";
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button
        type="{{ $type }}"
        @if ($hasLoadingState)
            wire:loading.attr="disabled"
            @if (filled($loadingTarget)) wire:target="{{ $loadingTarget }}" @endif
        @endif
        {{ $attributes->class($classes) }}
    >
        @if ($hasLoadingState)
            <svg
                wire:loading
                @if (filled($loadingTarget)) wire:target="{{ $loadingTarget }}" @endif
                class="size-3.5 animate-spin"
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
            >
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3" />
                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
            </svg>
        @endif
        {{ $slot }}
    </button>
@endif
