@props([
    'label' => '+ Enviar arquivo',
    'accept' => null,
    'multiple' => false,
])

@php
    $uploadTarget = $attributes->wire('model')->value() ?: null;
@endphp

<div
    x-data="{
        files: [],
        pick(event) {
            this.files.forEach((file) => file.url && URL.revokeObjectURL(file.url));
            this.files = Array.from(event.target.files).map((file) => ({
                name: file.name,
                size: file.size < 1048576 ? Math.max(1, Math.round(file.size / 1024)) + ' KB' : (file.size / 1048576).toFixed(1).replace('.', ',') + ' MB',
                url: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
            }));
        },
    }"
    {{ $attributes->only('class')->class('flex flex-col gap-2') }}
>
    <input
        type="file"
        x-ref="input"
        x-on:change="pick($event)"
        class="sr-only"
        tabindex="-1"
        @if (filled($accept)) accept="{{ $accept }}" @endif
        @if ($multiple) multiple @endif
        {{ $attributes->except('class') }}
    >

    <button
        type="button"
        x-on:click="$refs.input.click()"
        class="w-full rounded-[12px] border border-dashed border-black/15 bg-transparent px-3.5 py-3 text-left font-medium text-accent transition-colors hover:bg-white hover:text-accent-hover focus-visible:ring-[3px] focus-visible:ring-accent/20 focus-visible:outline-none"
    >
        {{ $label }}
    </button>

    @if ($uploadTarget !== null)
        <div wire:loading wire:target="{{ $uploadTarget }}" class="text-[12px] text-ink-secondary">Enviando…</div>
    @endif

    <ul x-show="files.length > 0" x-cloak class="flex flex-col gap-1" data-file-list>
        <template x-for="file in files" x-bind:key="file.name">
            <li class="flex items-center gap-2 rounded-control-sm bg-surface-soft px-2.5 py-1.5 text-[12px]">
                <span class="min-w-0 flex-1 truncate text-ink-body" x-text="file.name"></span>
                <span class="shrink-0 text-ink-tertiary tabular-nums" x-text="file.size"></span>
            </li>
        </template>
    </ul>

    <div x-show="files.some((file) => file.url)" x-cloak class="grid grid-cols-2 gap-2" data-file-previews>
        <template x-for="file in files.filter((file) => file.url)" x-bind:key="file.url">
            <img x-bind:src="file.url" x-bind:alt="file.name" class="aspect-[4/3] w-full rounded-control-lg object-cover">
        </template>
    </div>

    {{ $slot }}
</div>
