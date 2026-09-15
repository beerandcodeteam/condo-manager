<?php

use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app', ['condominium' => ['name' => 'Residencial Aurora', 'units' => 128]])]
#[Title('Visão geral')]
class PanelLayoutPageComponent extends Component
{
    public function render(): string
    {
        return '<div>Conteúdo da página</div>';
    }
}

beforeEach(function () {
    $this->withoutVite();
});

/**
 * @param  array<string, mixed>  $data
 */
function renderPanelLayout(array $data = []): string
{
    return (string) test()->blade(<<<'BLADE'
        <x-layouts::app
            title="Visão geral"
            :condominium="$condominium"
            :condominiums="$condominiums"
            :can-switch="$canSwitch"
            :navigation="$navigation"
            :user="$user"
        >
            Conteúdo do painel
        </x-layouts::app>
        BLADE, array_merge([
        'condominium' => ['name' => 'Residencial Aurora', 'city' => 'Curitiba · PR', 'units' => 128],
        'condominiums' => [],
        'canSwitch' => false,
        'navigation' => [],
        'user' => ['name' => 'Renata Moura', 'role' => 'Síndico', 'condominium' => 'Residencial Aurora'],
    ], $data));
}

test('app layout renders the panel shell dimensions', function () {
    expect(renderPanelLayout())
        ->toContain('flex h-screen min-h-[720px] overflow-hidden')
        ->toContain('w-[236px]')
        ->toContain('bg-sidebar px-2.5 py-3.5')
        ->toContain('h-14')
        ->toContain('bg-[rgba(245,245,247,.8)]')
        ->toContain('backdrop-blur-md')
        ->toContain('text-[17px] font-semibold')
        ->toContain('px-7 pt-6 pb-10')
        ->toContain('min-w-[1000px]')
        ->toContain('<title>Visão geral · Síndico Conversacional</title>')
        ->toContain('Conteúdo do painel');
});

test('sidebar top card shows condominium initials, name and unit count', function () {
    expect(renderPanelLayout())
        ->toContain('>RA</span>')
        ->toContain('Residencial Aurora')
        ->toContain('128 unidades')
        ->not->toContain('data-condominium-switcher')
        ->not->toContain('▾');
});

test('sidebar switcher is rendered only when canSwitch is true', function () {
    $html = renderPanelLayout([
        'canSwitch' => true,
        'condominiums' => [
            ['name' => 'Residencial Aurora', 'city' => 'Curitiba · PR', 'current' => true],
            ['name' => 'Villa Serena', 'city' => 'Florianópolis · SC'],
        ],
    ]);

    expect($html)
        ->toContain('data-condominium-switcher')
        ->toContain('▾')
        ->toContain('Villa Serena')
        ->toContain('Florianópolis · SC')
        ->toContain('+ Novo condomínio');
});

test('sidebar renders navigation groups with item dot colors', function () {
    expect(renderPanelLayout())
        ->toContain('Operação')
        ->toContain('Base de conhecimento')
        ->toContain('Cadastro')
        ->toContain('Plataforma')
        ->toMatch('/data-nav-item="dashboard".*?bg-accent/s')
        ->toMatch('/data-nav-item="escalations".*?bg-dot-red/s')
        ->toMatch('/data-nav-item="tickets".*?bg-dot-orange/s')
        ->toMatch('/data-nav-item="reservations".*?bg-dot-cyan/s')
        ->toMatch('/data-nav-item="notices".*?bg-dot-grey/s')
        ->toMatch('/data-nav-item="rule-documents".*?bg-dot-grey/s')
        ->toMatch('/data-nav-item="residents".*?bg-dot-grey/s')
        ->toMatch('/data-nav-item="settings".*?bg-dot-grey/s')
        ->toMatch('/data-nav-item="condominiums".*?bg-dot-grey/s')
        ->toMatch('/data-nav-item="users".*?bg-dot-grey/s')
        ->toContain('Visão geral')
        ->toContain('Escalonamentos')
        ->toContain('Chamados')
        ->toContain('Reservas')
        ->toContain('Comunicados')
        ->toContain('Regimento')
        ->toContain('Moradores')
        ->toContain('Configurações')
        ->toContain('Condomínios')
        ->toContain('Usuários');
});

test('navigation items receive visibility, badge and active state by props', function () {
    $html = renderPanelLayout([
        'navigation' => [
            'escalations' => ['badge' => 3],
            'tickets' => ['active' => true],
            'condominiums' => ['visible' => false],
            'users' => ['visible' => false],
            'notices' => ['visible' => false],
        ],
    ]);

    expect($html)
        ->not->toContain('Plataforma')
        ->not->toContain('Condomínios')
        ->not->toContain('data-nav-item="notices"')
        ->toMatch('/data-nav-item="escalations".*?bg-accent.*?>\s*3\s*<\/span>/s')
        ->toMatch('/data-nav-item="tickets"\s+aria-current="page"\s+class="[^"]*bg-black\/7/');
});

test('sidebar footer shows avatar, name, role and logout menu', function () {
    expect(renderPanelLayout())
        ->toContain('size-[30px]')
        ->toContain('>RM</span>')
        ->toContain('Renata Moura')
        ->toContain('Síndico · Residencial Aurora')
        ->toContain('data-user-menu')
        ->toContain('method="POST"')
        ->toContain('Sair');
});

test('app layout does not render out of scope elements', function () {
    expect(renderPanelLayout())
        ->not->toContain('Conversas')
        ->not->toContain('⌘K')
        ->not->toContain('Agente online');
});

test('livewire page components render inside the app layout', function () {
    Route::livewire('/_layout-test', PanelLayoutPageComponent::class)->middleware('web');

    $this->get('/_layout-test')
        ->assertOk()
        ->assertSee('Conteúdo da página')
        ->assertSee('<h1 class="min-w-0 flex-1 truncate text-[17px] font-semibold tracking-[-0.01em]">Visão geral</h1>', false)
        ->assertSee('128 unidades');
});
