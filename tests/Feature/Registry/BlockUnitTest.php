<?php

use App\Models\Block;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\Resident;
use App\Models\Ticket;
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
});

test('units tab lists blocks and units with label, block and resident count', function () {
    $unit = Unit::factory()->for($this->condominium)->for($this->blockA)->create(['number' => '101']);
    Resident::factory()->count(2)->for($this->condominium)->for($unit)->create();
    Unit::factory()->for($this->condominium)->create(['number' => '12']);

    $this->actingAs($this->sindico)
        ->get(route('residents.index', ['aba' => 'unidades']))
        ->assertOk()
        ->assertSee('Moradores e unidades')
        ->assertSeeInOrder(['Moradores', 'Unidades'])
        ->assertSee('+ Bloco')
        ->assertSee('+ Unidade')
        ->assertSeeInOrder(['Bloco A', 'Bloco B'])
        ->assertSeeInOrder(['Unidade', 'Bloco', 'Moradores'])
        ->assertSeeInOrder(['12', 'Sem bloco', '0', '101A', 'Bloco A', '2']);
});

test('sindico creates a block and a unit', function () {
    actingInPanel($this->sindico);

    Livewire::test('registry.units')
        ->call('createBlock')
        ->set('blockName', 'C')
        ->call('saveBlock')
        ->assertHasNoErrors()
        ->call('createUnit')
        ->set('unitNumber', '301')
        ->set('unitBlockId', (string) Block::where('name', 'C')->value('id'))
        ->call('saveUnit')
        ->assertHasNoErrors()
        ->assertSee('301C')
        ->assertDispatched('registry-changed');

    expect(Unit::where('number', '301')->sole()->block->name)->toBe('C');
});

test('duplicated block name is rejected', function () {
    actingInPanel($this->sindico);

    Livewire::test('registry.units')
        ->call('createBlock')
        ->set('blockName', 'A')
        ->call('saveBlock')
        ->assertHasErrors(['blockName'])
        ->assertSee('Já existe um bloco com este nome neste condomínio.');

    expect(Block::where('name', 'A')->count())->toBe(1);
});

test('duplicated unit in the same block is rejected and in another block is accepted', function () {
    Unit::factory()->for($this->condominium)->for($this->blockA)->create(['number' => '101']);
    actingInPanel($this->sindico);

    $component = Livewire::test('registry.units')
        ->call('createUnit')
        ->set('unitNumber', '101')
        ->set('unitBlockId', (string) $this->blockA->id)
        ->call('saveUnit')
        ->assertHasErrors(['unitNumber'])
        ->assertSee('Já existe uma unidade com este número neste bloco.');

    expect(Unit::where('number', '101')->count())->toBe(1);

    $component->set('unitBlockId', (string) $this->blockB->id)
        ->call('saveUnit')
        ->assertHasNoErrors();

    expect(Unit::where('number', '101')->count())->toBe(2);
});

test('duplicated unit without block is rejected', function () {
    Unit::factory()->for($this->condominium)->create(['number' => '12']);
    actingInPanel($this->sindico);

    Livewire::test('registry.units')
        ->call('createUnit')
        ->set('unitNumber', '12')
        ->set('unitBlockId', '')
        ->call('saveUnit')
        ->assertHasErrors(['unitNumber'])
        ->assertSee('Já existe uma unidade sem bloco com este número neste condomínio.');

    expect(Unit::where('number', '12')->count())->toBe(1);
});

test('unit without block may repeat a number used in another condominium', function () {
    Unit::factory()->create(['number' => '12']);
    actingInPanel($this->sindico);

    Livewire::test('registry.units')
        ->call('createUnit')
        ->set('unitNumber', '12')
        ->call('saveUnit')
        ->assertHasNoErrors();
});

test('deleting a block with units is blocked', function () {
    Unit::factory()->for($this->condominium)->for($this->blockA)->create();
    actingInPanel($this->sindico);

    Livewire::test('registry.units')
        ->call('deleteBlock', $this->blockA->id)
        ->assertDispatched('toast', type: 'error', message: 'Remova as unidades do bloco antes de excluí-lo.');

    expect(Block::find($this->blockA->id))->not->toBeNull();
});

test('deleting an empty block works', function () {
    actingInPanel($this->sindico);

    Livewire::test('registry.units')
        ->call('deleteBlock', $this->blockB->id)
        ->assertDispatched('toast', type: 'success');

    expect(Block::find($this->blockB->id))->toBeNull();
});

test('deleting a unit with a resident is blocked', function () {
    $unit = Unit::factory()->for($this->condominium)->create();
    Resident::factory()->for($this->condominium)->for($unit)->create();
    actingInPanel($this->sindico);

    Livewire::test('registry.units')
        ->call('deleteUnit', $unit->id)
        ->assertDispatched('toast', type: 'error', message: 'Unidade com moradores, chamados ou reservas não pode ser excluída.');

    expect(Unit::find($unit->id))->not->toBeNull();
});

test('deleting a unit with tickets or reservations is blocked', function (string $related) {
    $unit = Unit::factory()->for($this->condominium)->create();

    if ($related === 'ticket') {
        Ticket::factory()->fromPanel()->for($this->condominium)->create(['unit_id' => $unit->id]);
    } else {
        $resident = Resident::factory()->for($this->condominium)->create();
        Reservation::factory()->for($this->condominium)->create(['unit_id' => $unit->id, 'resident_id' => $resident->id]);
    }

    actingInPanel($this->sindico);

    Livewire::test('registry.units')
        ->call('deleteUnit', $unit->id)
        ->assertDispatched('toast', type: 'error');

    expect(Unit::find($unit->id))->not->toBeNull();
})->with(['ticket', 'reservation']);

test('empty unit is deleted', function () {
    $unit = Unit::factory()->for($this->condominium)->create();
    actingInPanel($this->sindico);

    Livewire::test('registry.units')
        ->call('deleteUnit', $unit->id)
        ->assertDispatched('toast', type: 'success', message: 'Unidade excluída.');

    expect(Unit::find($unit->id))->toBeNull();
});

test('zelador receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();

    $this->actingAs($zelador)
        ->get(route('residents.index', ['aba' => 'unidades']))
        ->assertForbidden();

    actingInPanel($zelador);

    Livewire::test('registry.units')->assertForbidden();
});
