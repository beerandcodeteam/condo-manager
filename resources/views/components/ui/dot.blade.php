@props([
    'color' => 'grey',
    'size' => 8,
])

@php
    $colorClass = match ($color) {
        'red' => 'bg-dot-red',
        'orange' => 'bg-dot-orange',
        'green' => 'bg-dot-green',
        'cyan' => 'bg-dot-cyan',
        'grey' => 'bg-dot-grey',
        'accent' => 'bg-accent',
        default => null,
    };

    $sizeClass = (int) $size === 7 ? 'size-[7px]' : 'size-2';
@endphp

<span
    {{ $attributes->class(['inline-block shrink-0 rounded-full', $sizeClass, $colorClass]) }}
    @if ($colorClass === null) style="background: {{ $color }}" @endif
    aria-hidden="true"
></span>
