@props([
    'name' => null,
    'title' => null,
    'show' => false,
])

<div
    x-data="{ open: @js((bool) $show) }"
    x-modelable="open"
    {{ $attributes->except('class') }}
    x-on:keydown.escape.window="open = false"
    @if ($name !== null)
        x-on:open-drawer.window="if ($event.detail === @js($name) || $event.detail?.name === @js($name)) open = true"
        x-on:close-drawer.window="if ($event.detail === @js($name) || $event.detail?.name === @js($name)) open = false"
    @endif
>
    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        x-on:click="open = false"
        class="fixed inset-0 z-30 bg-black/18"
        data-drawer-overlay
    ></div>

    <div
        x-show="open"
        x-cloak
        x-trap="open"
        x-transition:enter="transition duration-200 ease-out"
        x-transition:enter-start="translate-x-6 opacity-0"
        x-transition:enter-end="translate-x-0 opacity-100"
        x-transition:leave="transition duration-150 ease-in"
        x-transition:leave-start="translate-x-0 opacity-100"
        x-transition:leave-end="translate-x-6 opacity-0"
        role="dialog"
        aria-modal="true"
        {{ $attributes->only('class')->class('fixed top-3 right-3 bottom-3 z-31 flex w-[460px] flex-col overflow-hidden rounded-drawer bg-white shadow-drawer') }}
    >
        <div class="flex items-start gap-2.5 border-b border-line px-6 pt-5 pb-4">
            <div class="min-w-0 flex-1">
                @isset($header)
                    {{ $header }}
                @elseif (filled($title))
                    <h2 class="text-[18px] font-semibold tracking-[-0.01em]">{{ $title }}</h2>
                @endisset
            </div>

            <button
                type="button"
                x-on:click="open = false"
                class="grid size-7 shrink-0 place-items-center rounded-full bg-tag-grey-bg text-[14px] text-[#4a4a55] transition-colors hover:bg-black/10"
                aria-label="Fechar"
            >&times;</button>
        </div>

        <div class="flex flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
            {{ $slot }}
        </div>

        @isset($footer)
            <div {{ $footer->attributes->class('flex gap-2 border-t border-line px-6 py-3.5') }}>
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
