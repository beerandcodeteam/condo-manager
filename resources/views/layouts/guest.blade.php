@props([
    'title' => null,
])

<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <div class="grid min-h-screen place-items-center px-4 py-10">
            <div class="flex w-[360px] max-w-full flex-col gap-5">
                <div class="flex items-center justify-center gap-2.5">
                    <span class="grid size-7 place-items-center rounded-control-sm bg-accent text-[12px] font-semibold text-white" aria-hidden="true">SC</span>
                    <span class="text-[15px] font-semibold tracking-[-0.01em]">Síndico Conversacional</span>
                </div>

                <div class="rounded-card border border-line bg-white px-6 py-6 shadow-card">
                    {{ $slot }}
                </div>
            </div>
        </div>

        <x-ui.toast />

        @livewireScripts
    </body>
</html>
