<div {{ $attributes->class('flex items-end gap-1 border-b border-black/8') }} role="tablist">
    {{ $slot }}

    @isset($actions)
        <span class="flex-1"></span>

        <div {{ $actions->attributes->class('mb-2 flex items-center gap-2') }}>
            {{ $actions }}
        </div>
    @endisset
</div>
