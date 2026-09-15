@props([
    'name' => null,
    'title' => null,
    'size' => 'md',
    'show' => false,
])

@php
    $widthClass = match ($size) {
        'sm' => 'max-w-[400px]',
        'lg' => 'max-w-[720px]',
        default => 'max-w-[540px]',
    };

    $hasHeader = filled($title) || isset($header);
@endphp

<div
    x-data="{ open: @js((bool) $show) }"
    x-modelable="open"
    {{ $attributes->except('class') }}
    x-on:keydown.escape.window="open = false"
    @if ($name !== null)
        x-on:open-modal.window="if ($event.detail === @js($name) || $event.detail?.name === @js($name)) open = true"
        x-on:close-modal.window="if ($event.detail === @js($name) || $event.detail?.name === @js($name)) open = false"
    @endif
>
    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        class="fixed inset-0 z-40 grid place-items-center overflow-y-auto bg-black/18 p-6"
        x-on:click.self="open = false"
        data-modal-overlay
    >
        <div
            x-show="open"
            x-trap="open"
            x-transition:enter="transition duration-150 ease-out"
            x-transition:enter-start="scale-95 opacity-0"
            x-transition:enter-end="scale-100 opacity-100"
            role="dialog"
            aria-modal="true"
            {{ $attributes->only('class')->class("flex w-full {$widthClass} flex-col overflow-hidden rounded-card border border-line bg-white shadow-drawer") }}
        >
            @if ($hasHeader)
                <div class="flex items-center gap-2.5 px-5 pt-4 pb-3">
                    <h2 class="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em]">{{ $header ?? $title }}</h2>

                    <button
                        type="button"
                        x-on:click="open = false"
                        class="grid size-7 shrink-0 place-items-center rounded-full bg-tag-grey-bg text-[14px] text-[#4a4a55] transition-colors hover:bg-black/10"
                        aria-label="Fechar"
                    >&times;</button>
                </div>
            @endif

            <div @class(['flex flex-col gap-3 px-5 pb-4 text-ink-body', 'pt-5' => ! $hasHeader])>
                {{ $slot }}
            </div>

            @isset($footer)
                <div {{ $footer->attributes->class('flex items-center justify-end gap-2 border-t border-line bg-surface-input px-5 py-3') }}>
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
