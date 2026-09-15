@props([
    'columns',
])

<div {{ $attributes->class('overflow-hidden rounded-card border border-line bg-white shadow-card') }} role="table">
    @isset($head)
        <div
            {{ $head->attributes->class('grid items-center gap-3 border-b border-line px-[18px] py-2.5 text-[11px] font-semibold tracking-[0.04em] text-ink-tertiary uppercase') }}
            style="grid-template-columns: {{ $columns }}"
            role="row"
        >
            {{ $head }}
        </div>
    @endisset

    {{ $slot }}
</div>
