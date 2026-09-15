<?php

test('card renders surface tokens, header title and action slot', function () {
    $this->blade(<<<'BLADE'
        <x-ui.card title="Atividade do agente">
            <x-slot:action><a href="/escalonamentos">Ver fila</a></x-slot:action>
            Conteúdo do card
        </x-ui.card>
        BLADE)
        ->assertSee('rounded-card border border-line bg-white shadow-card', false)
        ->assertSee('text-[14px] font-semibold', false)
        ->assertSeeInOrder(['Atividade do agente', 'Ver fila', 'Conteúdo do card']);
});

test('card without header renders only the body', function () {
    $this->blade('<x-ui.card>Somente corpo</x-ui.card>')
        ->assertSee('Somente corpo')
        ->assertSee('py-4', false)
        ->assertDontSee('text-[14px] font-semibold', false);
});

test('card body padding can be disabled', function () {
    $html = (string) $this->blade('<x-ui.card :padded="false">Linhas</x-ui.card>');

    expect($html)->not->toContain('px-5');
});

test('kpi card renders label, value and colored delta', function () {
    $this->blade('<x-ui.kpi-card label="Chamados abertos" value="12" delta="+3 hoje" delta-color="ok" />')
        ->assertSeeInOrder(['Chamados abertos', '12', '+3 hoje'])
        ->assertSee('text-[12px] font-medium text-ink-secondary', false)
        ->assertSee('text-[30px]', false)
        ->assertSee('tracking-[-0.02em]', false)
        ->assertSee('text-tag-ok-fg', false);
});

test('kpi card accepts a raw delta color', function () {
    $this->blade('<x-ui.kpi-card label="Urgentes" value="2" delta="alta prioridade" delta-color="#e5484d" />')
        ->assertSee('color: #e5484d', false);
});

test('tabs render counters, the active tab and the actions slot', function () {
    $this->blade(<<<'BLADE'
        <x-ui.tabs>
            <x-ui.tabs.tab :active="true" :count="8">Todos</x-ui.tabs.tab>
            <x-ui.tabs.tab :count="3" wire:click="$set('status', 'aberto')">Abertos</x-ui.tabs.tab>
            <x-slot:actions><button>+ Novo chamado</button></x-slot:actions>
        </x-ui.tabs>
        BLADE)
        ->assertSeeInOrder(['Todos', '8', 'Abertos', '3', '+ Novo chamado'])
        ->assertSee('border-ink text-ink', false)
        ->assertSee('border-b-2', false)
        ->assertSee('text-ink-tertiary', false)
        ->assertSee('aria-selected="true"', false)
        ->assertSee('wire:click="$set(\'status\', \'aberto\')"', false);
});

test('table renders header and rows with the given grid columns', function () {
    $this->blade(<<<'BLADE'
        <x-ui.table columns="90px minmax(0,2fr) 110px">
            <x-slot:head><span>Protocolo</span><span>Chamado</span><span>Aberto</span></x-slot:head>
            <x-ui.table.row><span>#12</span><span>Infiltração</span><span>ontem</span></x-ui.table.row>
            <x-ui.table.row clickable wire:click="open(12)"><span>#13</span><span>Lâmpada</span><span>hoje</span></x-ui.table.row>
            <x-ui.table.row href="/chamados/14"><span>#14</span><span>Portão</span><span>hoje</span></x-ui.table.row>
        </x-ui.table>
        BLADE)
        ->assertSee('grid-template-columns: 90px minmax(0,2fr) 110px', false)
        ->assertSee('uppercase', false)
        ->assertSee('tracking-[0.04em]', false)
        ->assertSee('px-[18px] py-3', false)
        ->assertSee('hover:bg-surface-soft', false)
        ->assertSee('<button type="button"', false)
        ->assertSee('wire:click="open(12)"', false)
        ->assertSee('<a href="/chamados/14"', false)
        ->assertSeeInOrder(['Protocolo', '#12', '#13', '#14']);
});

test('empty state renders icon, title and description', function () {
    $this->blade('<x-ui.empty-state title="Nenhum chamado" description="Os chamados abertos aparecem aqui." />')
        ->assertSee('<svg', false)
        ->assertSeeInOrder(['Nenhum chamado', 'Os chamados abertos aparecem aqui.']);
});
