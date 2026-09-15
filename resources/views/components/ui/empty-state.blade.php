@props([
    'title',
    'description' => null,
])

<div {{ $attributes->class('flex flex-col items-center justify-center gap-2 px-6 py-12 text-center') }}>
    <span class="mb-1 grid size-11 place-items-center rounded-full bg-tag-grey-bg text-dot-grey" aria-hidden="true">
        @isset($icon)
            {{ $icon }}
        @else
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 13h5l1.5 3h5L16 13h5" />
                <path d="M5.5 5h13L21 13v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-5z" />
            </svg>
        @endisset
    </span>

    <div class="text-[14px] font-semibold text-ink">{{ $title }}</div>

    @if (filled($description))
        <p class="max-w-sm text-[13px] text-ink-secondary">{{ $description }}</p>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-2 flex items-center gap-2">{{ $slot }}</div>
    @endif
</div>
