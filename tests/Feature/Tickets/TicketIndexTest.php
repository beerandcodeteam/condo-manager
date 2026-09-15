<?php

use App\Models\Block;
use App\Models\Condominium;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
    $this->travelTo('2026-09-15 12:38:00');

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create(['name' => 'Renata Moura']);
});

test('tabs show counts and concluídos includes resolved and cancelled tickets', function () {
    Ticket::factory()->count(2)->aberto()->for($this->condominium)->create();
    Ticket::factory()->emAndamento()->for($this->condominium)->create();
    Ticket::factory()->resolvido()->for($this->condominium)->create(['description' => 'Interfone resolvido']);
    Ticket::factory()->cancelado()->for($this->condominium)->create(['description' => 'Portão cancelado']);
    Ticket::factory()->aberto()->for(Condominium::factory())->create();

    $this->actingAs($this->sindico)
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertSeeTextInOrder(['Todos 5', 'Abertos 2', 'Em andamento 1', 'Concluídos 2', '+ Novo chamado']);

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('selectTab', 'concluidos')
        ->assertSet('tab', 'concluidos')
        ->assertSee('Interfone resolvido')
        ->assertSee('Portão cancelado')
        ->assertViewHas('tab', 'concluidos')
        ->tap(fn ($component) => expect($component->instance()->tickets->total())->toBe(2))
        ->call('selectTab', 'abertos')
        ->assertDontSee('Interfone resolvido')
        ->tap(fn ($component) => expect($component->instance()->tickets->total())->toBe(2))
        ->call('selectTab', 'em-andamento')
        ->tap(fn ($component) => expect($component->instance()->tickets->total())->toBe(1));

    $this->get(route('tickets.index', ['aba' => 'concluidos']))
        ->assertOk()
        ->assertSee('Interfone resolvido')
        ->assertSee('Portão cancelado');
});

test('rows show protocol, description with origin, unit, category, priority dot, status pill and opening date', function () {
    $category = TicketCategory::factory()->for($this->condominium)->create(['name' => 'Elevador']);
    $unit = Unit::factory()->for(Block::factory()->for($this->condominium)->state(['name' => 'A']))->create(['number' => '1201']);

    $this->travelTo('2026-09-15 12:38:00');
    Ticket::factory()->highPriority()->for($unit)->create([
        'description' => 'Elevador do bloco B parado',
        'ticket_category_id' => $category->id,
    ]);
    $this->travelTo('2026-09-14 21:20:00');
    Ticket::factory()->fromPanel()->emAndamento()->create([
        'condominium_id' => $this->condominium->id,
        'opened_by_user_id' => $this->sindico->id,
        'ticket_category_id' => null,
        'ticket_priority_id' => TicketPriority::idFor(TicketPriority::BAIXA),
        'description' => 'Lâmpada queimada garagem G2',
    ]);
    $this->travelTo('2026-09-13 10:00:00');
    Ticket::factory()->resolvido()->for($this->condominium)->create(['description' => 'Interfone sem áudio']);
    $this->travelTo('2026-09-15 12:38:00');

    actingInPanel($this->sindico);

    $html = Livewire::test('pages::tickets')->html();

    expect($html)
        ->toContain('grid-template-columns: 90px minmax(0,2fr) 90px 130px 100px 120px 110px')
        ->toContain('font-mono')
        ->toMatch('/#1<\/span>.*?Elevador do bloco B parado.*?WhatsApp · agente.*?1201A.*?Elevador.*?background: #e5484d.*?Alta.*?bg-tag-esc-bg text-tag-esc-fg[^>]*>Aberto.*?hoje 09:38/s')
        ->toMatch('/#2<\/span>.*?Lâmpada queimada garagem G2.*?Painel · Renata Moura.*?Área comum.*?—.*?background: #8e8e93.*?Baixa.*?bg-tag-warn-bg text-tag-warn-fg[^>]*>Em andamento.*?ontem 18:20/s')
        ->toMatch('/#3<\/span>.*?Interfone sem áudio.*?bg-tag-ok-bg text-tag-ok-fg[^>]*>Resolvido.*?13 set/s');
});

test('cancelled tickets use the grey pill and medium priority the orange dot', function () {
    Ticket::factory()->cancelado()->for($this->condominium)->create();
    actingInPanel($this->sindico);

    expect(Livewire::test('pages::tickets')->html())
        ->toMatch('/bg-tag-grey-bg text-tag-grey-fg[^>]*>Cancelado/')
        ->toContain('background: #f5a623');
});

test('priority and block filters are reflected in the query string', function () {
    $blockA = Block::factory()->for($this->condominium)->create(['name' => 'A']);
    $blockB = Block::factory()->for($this->condominium)->create(['name' => 'B']);

    Ticket::factory()->highPriority()->for(Unit::factory()->for($blockA))->create(['description' => 'Alta no bloco A']);
    Ticket::factory()->for(Unit::factory()->for($blockA))->create(['description' => 'Média no bloco A']);
    Ticket::factory()->highPriority()->for(Unit::factory()->for($blockB))->create(['description' => 'Alta no bloco B']);
    Ticket::factory()->fromPanel()->highPriority()->for($this->condominium)->create(['description' => 'Alta na área comum']);

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->set('priorityFilter', 'alta')
        ->assertSee('Alta no bloco A')
        ->assertSee('Alta no bloco B')
        ->assertSee('Alta na área comum')
        ->assertDontSee('Média no bloco A')
        ->assertSeeText('Todos 3')
        ->set('blockFilter', (string) $blockA->id)
        ->assertSee('Alta no bloco A')
        ->assertDontSee('Alta no bloco B')
        ->assertDontSee('Alta na área comum')
        ->assertSeeText('Todos 1');

    $this->get(route('tickets.index', ['prioridade' => 'alta', 'bloco' => $blockB->id]))
        ->assertOk()
        ->assertSee('Alta no bloco B')
        ->assertDontSee('Alta no bloco A')
        ->assertDontSee('Média no bloco A');
});

test('category filter lists only tickets of the category', function () {
    $elevator = TicketCategory::factory()->for($this->condominium)->create(['name' => 'Elevador']);
    Ticket::factory()->for($this->condominium)->create(['ticket_category_id' => $elevator->id, 'description' => 'Elevador parado']);
    Ticket::factory()->for($this->condominium)->create(['description' => 'Outra categoria']);

    $this->actingAs($this->sindico)
        ->get(route('tickets.index', ['categoria' => $elevator->id]))
        ->assertOk()
        ->assertSee('Elevador parado')
        ->assertDontSee('Outra categoria');
});

test('search finds by protocol number or description', function () {
    $tickets = Ticket::factory()->count(12)->sequence(fn ($sequence) => ['description' => 'Chamado número '.chr(65 + $sequence->index)])->for($this->condominium)->create();
    Ticket::factory()->for($this->condominium)->create(['description' => 'Infiltração no teto do banheiro']);

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->set('search', '12')
        ->assertSee('Chamado número L')
        ->assertDontSee('Chamado número A')
        ->assertDontSee('Infiltração')
        ->set('search', '#3')
        ->assertSee('Chamado número C')
        ->assertDontSee('Chamado número L')
        ->set('search', 'infiltração')
        ->assertSee('Infiltração no teto do banheiro')
        ->assertDontSee('Chamado número C');

    $this->actingAs($this->sindico)
        ->get(route('tickets.index', ['busca' => '12']))
        ->assertSee('Chamado número L')
        ->assertDontSee('Chamado número A');

    expect($tickets)->toHaveCount(12);
});

test('the list is paginated by 25 ordered by opening date descending', function () {
    foreach (range(1, 26) as $index) {
        Ticket::factory()->for($this->condominium)->create([
            'description' => sprintf('Chamado %02d', $index),
            'created_at' => now()->subHours(30 - $index),
        ]);
    }

    actingInPanel($this->sindico);

    $component = Livewire::test('pages::tickets');

    expect($component->instance()->tickets->perPage())->toBe(25)
        ->and($component->instance()->tickets->total())->toBe(26)
        ->and($component->instance()->tickets->first()->description)->toBe('Chamado 26');

    $component->assertSeeInOrder(['Chamado 26', 'Chamado 25', 'Chamado 02'])
        ->assertDontSee('Chamado 01');
});

test('zelador sees every ticket of the condominium', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();
    Ticket::factory()->for($this->condominium)->create(['description' => 'Aberto pelo agente']);
    Ticket::factory()->fromPanel()->for($this->condominium)->create(['description' => 'Aberto pela síndica']);

    $this->actingAs($zelador)
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertSee('Aberto pelo agente')
        ->assertSee('Aberto pela síndica')
        ->assertSeeText('Todos 2');
});

test('tickets of another condominium are not listed nor counted', function () {
    Ticket::factory()->for($this->condominium)->create(['description' => 'Chamado da Aurora']);
    Ticket::factory()->count(3)->for(Condominium::factory())->create(['description' => 'Chamado do vizinho']);

    $this->actingAs($this->sindico)
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertSee('Chamado da Aurora')
        ->assertDontSee('Chamado do vizinho')
        ->assertSeeTextInOrder(['Todos 1', 'Abertos 1']);
});

test('guests are redirected to login', function () {
    $this->get(route('tickets.index'))->assertRedirect(route('login'));
});
