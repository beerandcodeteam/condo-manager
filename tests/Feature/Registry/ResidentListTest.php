<?php

use App\Models\AgentToolCall;
use App\Models\Block;
use App\Models\Condominium;
use App\Models\Resident;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
    [$this->blockA, $this->blockB] = Block::factory()->count(2)->for($this->condominium)->sequence(['name' => 'A'], ['name' => 'B'])->create();

    $this->helena = Resident::factory()->owner()->for($this->condominium)->create([
        'name' => 'Helena Barros',
        'phone' => '+5541992011100',
        'unit_id' => Unit::factory()->for($this->condominium)->for($this->blockA)->create(['number' => '101'])->id,
    ]);
    $this->marina = Resident::factory()->tenant()->for($this->condominium)->create([
        'name' => 'Marina Souza',
        'phone' => '+5541996550021',
        'unit_id' => Unit::factory()->for($this->condominium)->for($this->blockB)->create(['number' => '402'])->id,
    ]);
    $this->carlos = Resident::factory()->owner()->inactive()->for($this->condominium)->create([
        'name' => 'Carlos Mendes',
        'phone' => '+5541998123344',
        'unit_id' => Unit::factory()->for($this->condominium)->for($this->blockA)->create(['number' => '1201'])->id,
    ]);
});

test('residents tab renders chips, table columns ordered by unit label and no out of scope elements', function () {
    $this->actingAs($this->sindico)
        ->get(route('residents.index'))
        ->assertOk()
        ->assertSee('Moradores e unidades')
        ->assertSeeInOrder(['Todos · 3', 'Bloco A', 'Bloco B', '+ Morador'])
        ->assertSeeInOrder(['Unidade', 'Nome', 'Telefone', 'Perfil', 'Interações'])
        ->assertSeeInOrder([
            '101A', 'Helena Barros', '+55 41 99201-1100', 'Proprietário',
            '402B', 'Marina Souza', '+55 41 99655-0021', 'Inquilino',
            '1201A', 'Carlos Mendes', '+55 41 99812-3344', 'Proprietário',
        ])
        ->assertSee('>HB</span>', false)
        ->assertDontSee('Importar planilha')
        ->assertDontSee('Sem WhatsApp')
        ->assertDontSee('WhatsApp');
});

test('the all chip is black when no block is selected and the block chip becomes black when active', function () {
    actingInPanel($this->sindico);

    $component = Livewire::test('registry.residents');

    expect($component->html())->toMatch('/bg-ink font-semibold text-white[^>]*aria-pressed="true"[^>]*data-block-chip="all"/');

    $component->call('selectBlock', $this->blockB->id);

    expect($component->html())->toMatch('/bg-ink font-semibold text-white[^>]*aria-pressed="true"[^>]*data-block-chip="'.$this->blockB->id.'"/');
});

test('block chip filters', function () {
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('selectBlock', $this->blockA->id)
        ->assertSee('Helena Barros')
        ->assertSee('Carlos Mendes')
        ->assertDontSee('Marina Souza')
        ->call('selectBlock')
        ->assertSee('Marina Souza');
});

test('search by phone finds the resident', function (string $term) {
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->set('search', $term)
        ->assertSee('Marina Souza')
        ->assertDontSee('Helena Barros')
        ->assertDontSee('Carlos Mendes');
})->with(['formatted' => '99655-0021', 'digits' => '996550021']);

test('search by name finds the resident', function () {
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->set('search', 'helena')
        ->assertSee('Helena Barros')
        ->assertDontSee('Marina Souza');
});

test('unit and status filters', function () {
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->assertSet('statusFilter', 'todos')
        ->set('unitFilter', (string) $this->marina->unit_id)
        ->assertSee('Marina Souza')
        ->assertDontSee('Helena Barros')
        ->set('unitFilter', '')
        ->set('statusFilter', 'inativos')
        ->assertSee('Carlos Mendes')
        ->assertDontSee('Helena Barros')
        ->set('statusFilter', 'ativos')
        ->assertSee('Helena Barros')
        ->assertSee('Marina Souza')
        ->assertDontSee('Carlos Mendes');
});

test('interactions column matches the tool call count of the resident', function () {
    AgentToolCall::factory()->count(4)->for($this->condominium)->create(['resident_id' => $this->helena->id, 'created_at' => now()->subDays(30)]);
    AgentToolCall::factory()->count(2)->for($this->condominium)->create(['resident_id' => $this->marina->id]);
    AgentToolCall::factory()->for($this->condominium)->create(['resident_id' => null]);
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->assertSeeHtml('data-resident-row="'.$this->helena->id.'"')
        ->assertSeeHtmlInOrder(['Helena Barros', 'data-interactions="4"', 'Marina Souza', 'data-interactions="2"', 'Carlos Mendes', 'data-interactions="0"']);
});

test('residents of another condominium do not appear', function () {
    Resident::factory()->create(['name' => 'Morador de Fora']);
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->assertSee('Todos · 3')
        ->assertDontSee('Morador de Fora');

    $this->actingAs($this->sindico)
        ->get(route('residents.index'))
        ->assertDontSee('Morador de Fora');
});

test('the list shows 25 residents per page', function () {
    Resident::factory()->count(25)->for($this->condominium)->create();
    actingInPanel($this->sindico);

    $residents = Livewire::test('registry.residents')->instance()->residents;

    expect($residents->perPage())->toBe(25)
        ->and($residents->total())->toBe(28)
        ->and($residents->count())->toBe(25);
});

test('zelador receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();

    $this->actingAs($zelador)
        ->get(route('residents.index'))
        ->assertForbidden();

    actingInPanel($zelador);

    Livewire::test('registry.residents')->assertForbidden();
});
