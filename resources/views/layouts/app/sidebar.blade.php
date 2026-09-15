@props([
    'condominium' => null,
    'condominiums' => [],
    'canSwitch' => false,
    'newCondominiumUrl' => null,
    'navigation' => [],
    'user' => null,
])

@php
    $groups = [
        'Operação' => [
            'dashboard' => ['label' => 'Visão geral', 'dot' => 'bg-accent', 'route' => 'dashboard', 'pattern' => 'dashboard'],
            'escalations' => ['label' => 'Escalonamentos', 'dot' => 'bg-dot-red', 'route' => 'escalations.index', 'pattern' => 'escalations.*'],
            'tickets' => ['label' => 'Chamados', 'dot' => 'bg-dot-orange', 'route' => 'tickets.index', 'pattern' => 'tickets.*'],
            'reservations' => ['label' => 'Reservas', 'dot' => 'bg-dot-cyan', 'route' => 'reservations.index', 'pattern' => 'reservations.*'],
        ],
        'Base de conhecimento' => [
            'notices' => ['label' => 'Comunicados', 'dot' => 'bg-dot-grey', 'route' => 'notices.index', 'pattern' => 'notices.*'],
            'rule-documents' => ['label' => 'Regimento', 'dot' => 'bg-dot-grey', 'route' => 'rule-documents.index', 'pattern' => 'rule-documents.*'],
        ],
        'Cadastro' => [
            'residents' => ['label' => 'Moradores', 'dot' => 'bg-dot-grey', 'route' => 'residents.index', 'pattern' => 'residents.*'],
            'settings' => ['label' => 'Configurações', 'dot' => 'bg-dot-grey', 'route' => 'settings', 'pattern' => 'settings*'],
        ],
        'Plataforma' => [
            'condominiums' => ['label' => 'Condomínios', 'dot' => 'bg-dot-grey', 'route' => 'condominiums.index', 'pattern' => 'condominiums.*'],
            'users' => ['label' => 'Usuários', 'dot' => 'bg-dot-grey', 'route' => 'users.index', 'pattern' => 'users.*'],
        ],
    ];

    $menu = collect($groups)
        ->map(fn (array $items): array => collect($items)
            ->map(function (array $item, string $key) use ($navigation): array {
                $options = $navigation[$key] ?? [];

                return [
                    'key' => $key,
                    'label' => $item['label'],
                    'dot' => $item['dot'],
                    'visible' => (bool) ($options['visible'] ?? true),
                    'badge' => $options['badge'] ?? null,
                    'href' => $options['href'] ?? (Route::has($item['route']) ? route($item['route']) : '#'),
                    'active' => (bool) ($options['active'] ?? request()->routeIs($item['pattern'])),
                ];
            })
            ->filter(fn (array $item): bool => $item['visible'])
            ->values()
            ->all())
        ->filter(fn (array $items): bool => $items !== []);

    $condominiumName = data_get($condominium, 'name');
    $condominiumUnits = (int) data_get($condominium, 'units', 0);

    $userName = data_get($user, 'name') ?? auth()->user()?->name ?? '';
    $userSubtitle = collect([data_get($user, 'role'), data_get($user, 'condominium') ?? $condominiumName])->filter()->implode(' · ');

    $logoutUrl = Route::has('logout') ? route('logout') : url('/logout');
@endphp

<aside class="flex w-[236px] flex-none flex-col gap-3.5 border-r border-black/7 bg-sidebar px-2.5 py-3.5">
    @if (filled($condominiumName))
        <div class="relative" @if ($canSwitch) x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" @endif>
            <div
                @if ($canSwitch)
                    role="button"
                    tabindex="0"
                    aria-haspopup="true"
                    x-bind:aria-expanded="open ? 'true' : 'false'"
                    x-on:click="open = ! open"
                    x-on:keydown.enter.prevent="open = ! open"
                @endif
                @class([
                    'flex w-full items-center gap-2.5 rounded-control-lg border border-black/8 bg-white px-2.5 py-2 text-left shadow-[0_1px_2px_rgba(0,0,0,.04)]',
                    'cursor-pointer' => $canSwitch,
                ])
                data-condominium-card
            >
                <span class="grid size-7 shrink-0 place-items-center rounded-control-sm bg-accent text-[12px] font-semibold text-white">{{ Str::substr(Str::initials($condominiumName, capitalize: true), 0, 2) }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13px] font-semibold">{{ $condominiumName }}</span>
                    <span class="block text-[11px] text-ink-secondary">{{ $condominiumUnits }} {{ $condominiumUnits === 1 ? 'unidade' : 'unidades' }}</span>
                </span>
                @if ($canSwitch)
                    <span class="text-[10px] text-ink-tertiary" aria-hidden="true">▾</span>
                @endif
            </div>

            @if ($canSwitch)
                <div
                    x-show="open"
                    x-cloak
                    x-transition.opacity.duration.100ms
                    class="absolute top-[52px] right-0 left-0 z-20 flex flex-col gap-0.5 rounded-[12px] border border-black/8 bg-white p-1.5 shadow-popover"
                    data-condominium-switcher
                >
                    @foreach ($condominiums as $option)
                        <form method="POST" action="{{ data_get($option, 'url', '#') }}" wire:key="condominium-option-{{ $loop->index }}">
                            @csrf
                            <button
                                type="submit"
                                @if (data_get($option, 'current', false)) aria-current="true" @endif
                                @class([
                                    'flex w-full cursor-pointer items-center gap-2.5 rounded-control-sm px-2.5 py-2 text-left hover:bg-tag-grey-bg',
                                    'bg-tag-grey-bg' => (bool) data_get($option, 'current', false),
                                ])
                                data-condominium-option
                            >
                                <span class="grid size-6 shrink-0 place-items-center rounded-[7px] bg-accent text-[11px] font-semibold text-white">{{ Str::substr(Str::initials((string) data_get($option, 'name'), capitalize: true), 0, 2) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium">{{ data_get($option, 'name') }}</span>
                                    <span class="block text-[11px] text-ink-secondary">{{ data_get($option, 'city') }}</span>
                                </span>
                            </button>
                        </form>
                    @endforeach

                    <div class="my-1 border-t border-black/7"></div>

                    <a href="{{ $newCondominiumUrl ?? '#' }}" class="rounded-control-sm px-2.5 py-2 font-medium text-accent hover:bg-tag-grey-bg">+ Novo condomínio</a>
                </div>
            @endif
        </div>
    @endif

    <nav class="flex flex-1 flex-col gap-3.5 overflow-y-auto">
        @foreach ($menu as $groupLabel => $items)
            <div class="flex flex-col gap-0.5" wire:key="nav-group-{{ $loop->index }}">
                <div class="px-2.5 pb-1.5 text-[11px] font-semibold tracking-[0.04em] text-ink-tertiary uppercase">{{ $groupLabel }}</div>

                @foreach ($items as $item)
                    <a
                        href="{{ $item['href'] }}"
                        wire:key="nav-item-{{ $item['key'] }}"
                        data-nav-item="{{ $item['key'] }}"
                        @if ($item['active']) aria-current="page" @endif
                        @class([
                            'flex items-center gap-2.5 rounded-control-sm px-2.5 py-[7px] text-[13px] font-medium transition-colors',
                            'bg-black/7 text-ink' => $item['active'],
                            'text-ink-body hover:bg-black/5' => ! $item['active'],
                        ])
                    >
                        <span class="size-2 flex-none rounded-[3px] {{ $item['dot'] }}" aria-hidden="true"></span>
                        <span class="flex-1">{{ $item['label'] }}</span>
                        @if (filled($item['badge']))
                            <x-ui.count-badge>{{ $item['badge'] }}</x-ui.count-badge>
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>

    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
        <div
            x-show="open"
            x-cloak
            x-transition.opacity.duration.100ms
            class="absolute right-0 bottom-[calc(100%+6px)] left-0 z-20 rounded-[12px] border border-black/8 bg-white p-1.5 shadow-popover"
            data-user-menu
        >
            <form method="POST" action="{{ $logoutUrl }}">
                @csrf
                <button type="submit" class="w-full rounded-control-sm px-2.5 py-2 text-left font-medium text-ink-body hover:bg-tag-grey-bg">Sair</button>
            </form>
        </div>

        <button
            type="button"
            x-on:click="open = ! open"
            class="flex w-full items-center gap-2.5 rounded-control-lg px-2.5 py-2 text-left transition-colors hover:bg-black/5"
            data-user-footer
        >
            <x-ui.avatar :name="$userName" :size="30" />
            <span class="min-w-0 flex-1">
                <span class="block truncate font-medium">{{ $userName }}</span>
                @if (filled($userSubtitle))
                    <span class="block truncate text-[11px] text-ink-secondary">{{ $userSubtitle }}</span>
                @endif
            </span>
        </button>
    </div>
</aside>
