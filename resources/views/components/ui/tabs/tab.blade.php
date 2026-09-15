@props([
    'active' => false,
    'count' => null,
    'href' => null,
])

@php
    $classes = [
        '-mb-px border-b-2 bg-transparent px-3.5 pt-2 pb-2.5 text-[13px] font-medium transition-colors',
        'border-ink text-ink' => $active,
        'border-transparent text-ink-body hover:text-ink' => ! $active,
    ];
@endphp

@if ($href !== null)
    <a href="{{ $href }}" role="tab" aria-selected="{{ $active ? 'true' : 'false' }}" {{ $attributes->class($classes) }}>
        {{ $slot }}@if ($count !== null) <span class="font-normal text-ink-tertiary">{{ $count }}</span>@endif
    </a>
@else
    <button type="button" role="tab" aria-selected="{{ $active ? 'true' : 'false' }}" {{ $attributes->class($classes) }}>
        {{ $slot }}@if ($count !== null) <span class="font-normal text-ink-tertiary">{{ $count }}</span>@endif
    </button>
@endif
