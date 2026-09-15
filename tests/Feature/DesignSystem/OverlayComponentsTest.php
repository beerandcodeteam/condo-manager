<?php

use Livewire\Component;
use Livewire\Livewire;

class OverlayHostComponent extends Component
{
    public bool $showDrawer = false;

    public bool $showModal = false;

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <x-ui.drawer wire:model="showDrawer" title="Chamado #12">
                    Descrição do chamado
                </x-ui.drawer>

                <x-ui.modal wire:model="showModal" title="Cancelar reserva" size="sm">
                    Tem certeza?
                </x-ui.modal>
            </div>
            BLADE;
    }
}

test('drawer renders design tokens, slots and close controls', function () {
    $html = (string) $this->blade(<<<'BLADE'
        <x-ui.drawer name="ticket">
            <x-slot:header><span>#12</span></x-slot:header>
            Corpo do drawer
            <x-slot:footer><button>Iniciar atendimento</button></x-slot:footer>
        </x-ui.drawer>
        BLADE);

    expect($html)
        ->toContain('fixed top-3 right-3 bottom-3')
        ->toContain('w-[460px]')
        ->toContain('rounded-drawer')
        ->toContain('shadow-drawer')
        ->toContain('bg-black/18')
        ->toContain('overflow-y-auto')
        ->toContain('x-on:keydown.escape.window="open = false"')
        ->toContain('data-drawer-overlay')
        ->toContain('aria-label="Fechar"')
        ->toContain('size-7')
        ->toContain('bg-tag-grey-bg')
        ->toContain('x-modelable="open"')
        ->toContain('open-drawer.window')
        ->and(substr_count($html, 'x-on:click="open = false"'))->toBe(2)
        ->and($html)->toContain('#12')
        ->toContain('Corpo do drawer')
        ->toContain('Iniciar atendimento');
});

test('modal renders centered card with size, title, body and footer', function (string $size, string $widthClass) {
    $this->blade(<<<'BLADE'
        <x-ui.modal title="Avisar morador" :size="$size">
            Mensagem ao morador
            <x-slot:footer><button>Enviar</button></x-slot:footer>
        </x-ui.modal>
        BLADE, ['size' => $size])
        ->assertSee($widthClass, false)
        ->assertSee('place-items-center', false)
        ->assertSee('rounded-card border border-line bg-white', false)
        ->assertSee('x-on:keydown.escape.window="open = false"', false)
        ->assertSee('x-on:click.self="open = false"', false)
        ->assertSeeInOrder(['Avisar morador', 'Mensagem ao morador', 'Enviar']);
})->with([
    ['sm', 'max-w-[400px]'],
    ['md', 'max-w-[540px]'],
    ['lg', 'max-w-[720px]'],
]);

test('drawer and modal state are bound with wire:model', function () {
    Livewire::test(OverlayHostComponent::class)
        ->assertSeeHtml('wire:model="showDrawer"')
        ->assertSeeHtml('wire:model="showModal"')
        ->assertSee('Descrição do chamado')
        ->set('showDrawer', true)
        ->assertSet('showDrawer', true)
        ->set('showModal', true)
        ->assertSet('showModal', true);
});

test('overlays can start open', function () {
    $this->blade('<x-ui.drawer :show="true">Aberto</x-ui.drawer>')
        ->assertSee('x-data="{ open: true }"', false);
});
