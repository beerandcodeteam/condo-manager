<?php

use App\Models\Condominium;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
});

test('tabs show active and inactive counters, new notice button, helper text and active cards', function () {
    Notice::factory()->for($this->condominium)->create([
        'title' => 'Manutenção da caixa d\'água',
        'body' => 'Quinta 18/09, sem abastecimento nos blocos A e B.',
        'updated_at' => '2026-09-15 11:00:00',
    ]);
    Notice::factory()->for($this->condominium)->create(['title' => 'Obra no hall']);
    Notice::factory()->for($this->condominium)->create(['title' => 'Excluído'])->delete();
    Notice::factory()->inactive()->for($this->condominium)->create(['title' => 'Dedetização antiga']);

    $this->actingAs($this->sindico)
        ->get(route('notices.index'))
        ->assertOk()
        ->assertSeeTextInOrder(['Ativos · 2', 'Inativos · 1', '+ Novo comunicado'])
        ->assertSee('Só comunicados <b class="font-semibold text-ink">ativos</b> chegam ao agente. Inativos ficam no histórico e não são citados.', false)
        ->assertSeeInOrder(['Ativo', 'Manutenção da caixa d&#039;água', 'Quinta 18/09, sem abastecimento nos blocos A e B.', 'Atualizado em 15/09', 'Editar'], false)
        ->assertSee('Obra no hall')
        ->assertDontSee('Dedetização antiga')
        ->assertDontSee('Excluído')
        ->assertDontSee('respostas')
        ->assertDontSee('Válido');
});

test('cards are laid out in a two column grid with an ok pill for active notices', function () {
    Notice::factory()->for($this->condominium)->create();
    actingInPanel($this->sindico);

    expect(Livewire::test('pages::notices')->html())
        ->toContain('grid grid-cols-2')
        ->toContain('bg-tag-ok-bg text-tag-ok-fg')
        ->toContain('text-[15px] font-semibold')
        ->not->toContain('opacity-75');
});

test('inactive tab lists only inactive notices with grey pill and reduced opacity', function () {
    Notice::factory()->for($this->condominium)->create(['title' => 'Obra no hall']);
    Notice::factory()->inactive()->for($this->condominium)->create(['title' => 'Dedetização antiga']);
    actingInPanel($this->sindico);

    $component = Livewire::test('pages::notices')
        ->call('selectTab', 'inativos')
        ->assertSet('tab', 'inativos')
        ->assertSee('Dedetização antiga')
        ->assertSee('Inativo')
        ->assertDontSee('Obra no hall');

    expect($component->html())
        ->toContain('opacity-75')
        ->toContain('bg-tag-grey-bg text-tag-grey-fg');

    $this->actingAs($this->sindico)
        ->get(route('notices.index', ['aba' => 'inativos']))
        ->assertOk()
        ->assertSee('Dedetização antiga')
        ->assertDontSee('Obra no hall');
});

test('notices of another condominium do not appear nor count', function () {
    Notice::factory()->for($this->condominium)->create(['title' => 'Obra no hall']);
    Notice::factory()->for(Condominium::factory())->create(['title' => 'Aviso do vizinho']);
    Notice::factory()->inactive()->for(Condominium::factory())->create(['title' => 'Inativo do vizinho']);

    $this->actingAs($this->sindico)
        ->get(route('notices.index'))
        ->assertOk()
        ->assertSeeTextInOrder(['Ativos · 1', 'Inativos · 0'])
        ->assertSee('Obra no hall')
        ->assertDontSee('Aviso do vizinho');

    actingInPanel($this->sindico);

    Livewire::test('pages::notices')
        ->call('selectTab', 'inativos')
        ->assertDontSee('Inativo do vizinho');
});

test('super admin sees the notices of the selected condominium', function () {
    Notice::factory()->for($this->condominium)->create(['title' => 'Obra no hall']);
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->withSession(['current_condominium_id' => $this->condominium->id])
        ->get(route('notices.index'))
        ->assertOk()
        ->assertSee('Obra no hall');
});

test('zelador receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();

    $this->actingAs($zelador)
        ->get(route('notices.index'))
        ->assertForbidden();

    actingInPanel($zelador);

    Livewire::test('pages::notices')->assertForbidden();
});
