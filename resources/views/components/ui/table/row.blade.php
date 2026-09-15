@aware([
    'columns',
])

@props([
    'href' => null,
    'clickable' => false,
])

@php
    $isInteractive = $href !== null || $clickable;

    $classes = [
        'grid w-full items-center gap-3 border-b border-black/5 px-[18px] py-3 text-left transition-colors last:border-b-0 hover:bg-surface-soft',
        'cursor-pointer' => $isInteractive,
    ];
@endphp

@if ($href !== null)
    <a href="{{ $href }}" style="grid-template-columns: {{ $columns }}" role="row" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@elseif ($clickable)
    <button type="button" style="grid-template-columns: {{ $columns }}" role="row" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@else
    <div style="grid-template-columns: {{ $columns }}" role="row" {{ $attributes->class($classes) }}>{{ $slot }}</div>
@endif
