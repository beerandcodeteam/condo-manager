@props([
    'title' => null,
    'padded' => true,
])

@php
    $hasHeader = filled($title) || isset($header) || isset($action);
@endphp

<div {{ $attributes->class('overflow-hidden rounded-card border border-line bg-white shadow-card') }}>
    @if ($hasHeader)
        <div class="flex items-center gap-3 px-5 pt-4 pb-3">
            <div class="min-w-0 flex-1 text-[14px] font-semibold">
                {{ $header ?? $title }}
            </div>

            @isset($action)
                <div {{ $action->attributes->class('flex shrink-0 items-center gap-2 text-[12px] font-medium') }}>
                    {{ $action }}
                </div>
            @endisset
        </div>
    @endif

    <div @class([
        'px-5' => $padded,
        'pb-4' => $padded && $hasHeader,
        'py-4' => $padded && ! $hasHeader,
    ])>
        {{ $slot }}
    </div>
</div>
