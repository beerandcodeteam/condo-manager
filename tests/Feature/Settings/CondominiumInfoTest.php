<?php

use App\Models\Block;
use App\Models\Condominium;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create([
        'name' => 'Residencial Aurora',
        'city' => null,
        'whatsapp_number' => null,
        'caretaker_name' => null,
        'caretaker_phone' => null,
        'quiet_hours_start' => null,
        'quiet_hours_end' => null,
    ]);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
});

test('settings page renders the condominium card in a two column grid without the agent behavior card', function () {
    [$blockA, $blockB] = Block::factory()->count(2)->for($this->condominium)->sequence(['name' => 'A'], ['name' => 'B'])->create();
    Unit::factory()->count(2)->for($this->condominium)->for($blockA)->create();
    Unit::factory()->for($this->condominium)->for($blockB)->create();

    $this->actingAs($this->sindico)
        ->get(route('settings'))
        ->assertOk()
        ->assertSee('grid-cols-2', false)
        ->assertSee('data-settings-card="condominium"', false)
        ->assertSeeInOrder(['Nome', 'Residencial Aurora', 'Cidade', 'Unidades', '3 · 2 blocos', 'Número WhatsApp', 'Zelador', 'Horário de silêncio', 'Salvar'])
        ->assertDontSee('Comportamento do agente');
});

test('sindico updates city, whatsapp, caretaker and quiet hours', function () {
    actingInPanel($this->sindico);

    Livewire::test('settings.condominium-info')
        ->set('city', 'Curitiba · PR')
        ->set('whatsappNumber', '+55 (41) 3000-2200')
        ->set('caretakerName', 'José Carvalho')
        ->set('caretakerPhone', '+55 41 99555-0102')
        ->set('quietHoursStart', '21:30')
        ->set('quietHoursEnd', '07:00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('quietHoursStart', '21:30');

    expect($this->condominium->fresh())
        ->city->toBe('Curitiba · PR')
        ->whatsapp_number->toBe('+554130002200')
        ->caretaker_name->toBe('José Carvalho')
        ->caretaker_phone->toBe('+5541995550102')
        ->quiet_hours_start->toStartWith('21:30')
        ->quiet_hours_end->toStartWith('07:00');
});

test('invalid phone is rejected', function (string $field) {
    actingInPanel($this->sindico);

    Livewire::test('settings.condominium-info')
        ->set($field, '41999990000')
        ->call('save')
        ->assertHasErrors([$field])
        ->assertSee('Informe o telefone no formato internacional, ex.: +5511999990000.');

    expect($this->condominium->fresh()->whatsapp_number)->toBeNull()
        ->and($this->condominium->fresh()->caretaker_phone)->toBeNull();
})->with(['whatsappNumber', 'caretakerPhone']);

test('informing only the start of the quiet hours is rejected', function () {
    actingInPanel($this->sindico);

    Livewire::test('settings.condominium-info')
        ->set('quietHoursStart', '22:00')
        ->set('quietHoursEnd', '')
        ->call('save')
        ->assertHasErrors(['quietHoursEnd' => 'required_with'])
        ->assertSee('Informe o início e o fim do horário de silêncio.');

    expect($this->condominium->fresh()->quiet_hours_start)->toBeNull();
});

test('quiet hours crossing midnight 22:00–08:00 are accepted', function () {
    actingInPanel($this->sindico);

    Livewire::test('settings.condominium-info')
        ->set('quietHoursStart', '22:00')
        ->set('quietHoursEnd', '08:00')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->condominium->fresh())
        ->quiet_hours_start->toStartWith('22:00')
        ->quiet_hours_end->toStartWith('08:00');
});

test('all editable fields are optional', function () {
    $this->condominium->update(['city' => 'Curitiba', 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '08:00']);
    actingInPanel($this->sindico);

    Livewire::test('settings.condominium-info')
        ->set('city', '')
        ->set('quietHoursStart', '')
        ->set('quietHoursEnd', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->condominium->fresh())
        ->city->toBeNull()
        ->quiet_hours_start->toBeNull()
        ->quiet_hours_end->toBeNull();
});

test('name change by the sindico is ignored', function () {
    actingInPanel($this->sindico);

    Livewire::test('settings.condominium-info')
        ->assertSee('data-readonly-name', false)
        ->assertDontSee('id="settings-name" wire:model', false)
        ->set('name', 'Outro nome')
        ->set('city', 'Curitiba')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->condominium->fresh())
        ->name->toBe('Residencial Aurora')
        ->city->toBe('Curitiba');
});

test('super admin changes the name', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    actingInPanel($superAdmin, $this->condominium);

    Livewire::test('settings.condominium-info')
        ->assertDontSee('data-readonly-name', false)
        ->set('name', 'Residencial Aurora II')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->condominium->fresh()->name)->toBe('Residencial Aurora II');
});

test('zelador receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();

    $this->actingAs($zelador)
        ->get(route('settings'))
        ->assertForbidden();

    actingInPanel($zelador);

    Livewire::test('settings.condominium-info')->assertForbidden();
});
