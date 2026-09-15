{{--
    Painel autenticado.

    Props:
    - title: título da página (h1 do header e <title>).
    - condominium: array{name: string, city?: string|null, units?: int} do condomínio atual.
    - condominiums: list<array{name: string, city?: string|null, url?: string, current?: bool}> exibidos no seletor.
    - canSwitch: exibe a seta ▾ e o dropdown de condomínios.
    - newCondominiumUrl: destino de "+ Novo condomínio".
    - navigation: array<string, array{visible?: bool, badge?: int|null, href?: string, active?: bool}> por item do menu.
    - user: array{name: string, role?: string|null, condominium?: string|null} do rodapé.
--}}
@props([
    'title' => null,
    'condominium' => null,
    'condominiums' => [],
    'canSwitch' => false,
    'newCondominiumUrl' => null,
    'navigation' => [],
    'user' => null,
])

<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <div class="flex h-screen min-h-[720px] overflow-hidden">
            <x-layouts::app.sidebar
                :condominium="$condominium"
                :condominiums="$condominiums"
                :can-switch="$canSwitch"
                :new-condominium-url="$newCondominiumUrl"
                :navigation="$navigation"
                :user="$user"
            />

            <main class="flex min-w-0 flex-1 flex-col overflow-hidden">
                <header class="flex h-14 flex-none items-center gap-4 border-b border-line bg-[rgba(245,245,247,.8)] px-7 backdrop-blur-md">
                    <h1 class="min-w-0 flex-1 truncate text-[17px] font-semibold tracking-[-0.01em]">{{ $title }}</h1>

                    @isset($actions)
                        <div {{ $actions->attributes->class('flex shrink-0 items-center gap-2') }}>{{ $actions }}</div>
                    @endisset
                </header>

                <div class="flex-1 overflow-auto px-7 pt-6 pb-10">
                    <div class="min-w-[1000px]">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>

        <x-ui.toast />

        @livewireScripts
    </body>
</html>
