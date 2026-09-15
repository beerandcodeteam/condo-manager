{{--
    Mensagens flash do painel.

    Session flash: session()->flash('success', '...') ou session()->flash('error', '...').
    Livewire: $this->dispatch('toast', type: 'success'|'error', message: '...').
--}}
@props([
    'duration' => 4000,
])

@php
    $initialToasts = collect(['success', 'error'])
        ->filter(fn (string $type): bool => filled(session($type)))
        ->map(fn (string $type): array => ['type' => $type, 'message' => (string) session($type)])
        ->values()
        ->all();
@endphp

<div
    x-data="{
        toasts: [],
        nextId: 1,
        push(detail) {
            const payload = Array.isArray(detail) ? detail[0] : detail;

            if (! payload || ! payload.message) {
                return;
            }

            const id = this.nextId++;

            this.toasts.push({ id, type: payload.type === 'error' ? 'error' : 'success', message: payload.message });

            setTimeout(() => this.dismiss(id), @js((int) $duration));
        },
        dismiss(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },
    }"
    x-init="@js($initialToasts).forEach((toast) => push(toast))"
    x-on:toast.window="push($event.detail)"
    {{ $attributes->class('pointer-events-none fixed top-4 right-4 z-50 flex w-[340px] flex-col gap-2') }}
    aria-live="polite"
    data-toast-stack
>
    <template x-for="toast in toasts" x-bind:key="toast.id">
        <div
            x-transition.opacity.duration.150ms
            role="status"
            class="pointer-events-auto flex items-start gap-2.5 rounded-control-lg border border-line px-3.5 py-2.5 text-[13px] font-medium shadow-popover"
            x-bind:class="toast.type === 'error' ? 'bg-tag-esc-bg text-tag-esc-fg' : 'bg-tag-ok-bg text-tag-ok-fg'"
        >
            <span
                class="mt-[5px] size-2 shrink-0 rounded-full"
                x-bind:class="toast.type === 'error' ? 'bg-dot-red' : 'bg-dot-green'"
                aria-hidden="true"
            ></span>
            <span class="min-w-0 flex-1" x-text="toast.message"></span>
            <button type="button" x-on:click="dismiss(toast.id)" class="shrink-0 text-[14px] leading-none opacity-60 hover:opacity-100" aria-label="Fechar">&times;</button>
        </div>
    </template>

    <noscript>
        @foreach ($initialToasts as $toast)
            <div @class([
                'pointer-events-auto rounded-control-lg px-3.5 py-2.5 text-[13px] font-medium',
                'bg-tag-esc-bg text-tag-esc-fg' => $toast['type'] === 'error',
                'bg-tag-ok-bg text-tag-ok-fg' => $toast['type'] === 'success',
            ])>{{ $toast['message'] }}</div>
        @endforeach
    </noscript>
</div>
