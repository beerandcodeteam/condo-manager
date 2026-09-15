@props([
    'label' => null,
    'name' => null,
    'for' => null,
    'hint' => null,
    'layout' => 'stacked',
])

@php
    $errorMessage = $name !== null && isset($errors) ? $errors->first($name) : null;
    $isGrid = $layout === 'grid';
@endphp

<div {{ $attributes->class([
    'grid grid-cols-[150px_minmax(0,1fr)] items-center gap-x-3 gap-y-1' => $isGrid,
    'flex flex-col gap-1.5' => ! $isGrid,
]) }}>
    @if (filled($label))
        <label
            @if (filled($for)) for="{{ $for }}" @endif
            @class([
                'text-[12px] text-ink-secondary' => $isGrid,
                'text-[12px] font-medium text-ink-body' => ! $isGrid,
            ])
        >{{ $label }}</label>
    @elseif ($isGrid)
        <span></span>
    @endif

    <div class="min-w-0">{{ $slot }}</div>

    @if (filled($hint) && blank($errorMessage))
        @if ($isGrid)
            <span></span>
        @endif
        <p class="text-[12px] text-ink-tertiary">{{ $hint }}</p>
    @endif

    @if (filled($errorMessage))
        @if ($isGrid)
            <span></span>
        @endif
        <p class="text-[12px] text-tag-esc-fg" role="alert">{{ $errorMessage }}</p>
    @endif
</div>
