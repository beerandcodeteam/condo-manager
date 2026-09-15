@props([
    'label' => null,
    'description' => null,
    'name' => null,
    'checked' => false,
    'disabled' => false,
])

<div
    x-data="{ on: @js((bool) $checked) }"
    x-modelable="on"
    {{ $attributes->except('class') }}
    {{ $attributes->only('class')->class('flex items-center gap-3') }}
>
    @if (filled($label) || filled($description))
        <span class="min-w-0 flex-1">
            @if (filled($label))
                <span class="block font-medium">{{ $label }}</span>
            @endif
            @if (filled($description))
                <span class="block text-[12px] text-ink-secondary">{{ $description }}</span>
            @endif
        </span>
    @endif

    <button
        type="button"
        role="switch"
        x-on:click="on = ! on"
        x-bind:aria-checked="on ? 'true' : 'false'"
        aria-checked="{{ $checked ? 'true' : 'false' }}"
        class="group relative h-6 w-10 shrink-0 rounded-[12px] bg-switch-off transition-colors duration-200 outline-none focus-visible:ring-[3px] focus-visible:ring-accent/20 disabled:cursor-not-allowed disabled:opacity-60 aria-checked:bg-accent"
        @if (filled($label)) aria-label="{{ $label }}" @endif
        @disabled($disabled)
    >
        <span class="absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow-[0_1px_3px_rgba(0,0,0,.2)] transition-[left] duration-200 group-aria-checked:left-[18px]"></span>
    </button>

    @if (filled($name))
        <input type="hidden" name="{{ $name }}" x-bind:value="on ? 1 : 0" value="{{ $checked ? 1 : 0 }}">
    @endif
</div>
