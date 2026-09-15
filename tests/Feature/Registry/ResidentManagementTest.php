<?php

use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Escalation;
use App\Models\Reservation;
use App\Models\Resident;
use App\Models\ResidentProfile;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
    $this->unit = Unit::factory()->for($this->condominium)->create(['number' => '101']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function residentForm(array $overrides = []): array
{
    return [
        'name' => 'Helena Barros',
        'phone' => '+55 (41) 99201-1100',
        'unitId' => (string) test()->unit->id,
        'profile' => ResidentProfile::PROPRIETARIO,
        'isActive' => true,
        ...$overrides,
    ];
}

test('creates a resident with the phone normalized to E.164', function () {
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('create')
        ->assertSet('showForm', true)
        ->fill(residentForm(['profile' => ResidentProfile::INQUILINO]))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSee('Helena Barros')
        ->assertDispatched('registry-changed');

    $resident = Resident::sole();

    expect($resident->phone)->toBe('+5541992011100')
        ->and($resident->condominium_id)->toBe($this->condominium->id)
        ->and($resident->unit_id)->toBe($this->unit->id)
        ->and($resident->resident_profile_id)->toBe(ResidentProfile::idFor(ResidentProfile::INQUILINO))
        ->and($resident->is_active)->toBeTrue();
});

test('name, phone, unit and profile are required', function (string $field) {
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('create')
        ->fill(residentForm([$field => '']))
        ->call('save')
        ->assertHasErrors([$field => 'required']);

    expect(Resident::count())->toBe(0);
})->with(['name', 'phone', 'unitId', 'profile']);

test('missing phone is rejected', function () {
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('create')
        ->fill(residentForm(['phone' => '']))
        ->call('save')
        ->assertHasErrors(['phone' => 'required'])
        ->assertSee('O campo telefone é obrigatório.');

    expect(Resident::count())->toBe(0);
});

test('invalid phone is rejected', function () {
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('create')
        ->fill(residentForm(['phone' => '41999990000']))
        ->call('save')
        ->assertHasErrors(['phone'])
        ->assertSee('Informe o telefone no formato internacional, ex.: +5511999990000.');

    expect(Resident::count())->toBe(0);
});

test('unit of another condominium is rejected', function () {
    $otherUnit = Unit::factory()->create();
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('create')
        ->fill(residentForm(['unitId' => (string) $otherUnit->id]))
        ->call('save')
        ->assertHasErrors(['unitId']);
});

test('phone repeated in the condominium is rejected and in another condominium is accepted', function () {
    Resident::factory()->for($this->condominium)->create(['phone' => '+5541992011100']);
    Resident::factory()->create(['phone' => '+5541996550021']);
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('create')
        ->fill(residentForm(['phone' => '+55 41 99201-1100']))
        ->call('save')
        ->assertHasErrors(['phone'])
        ->assertSee('Telefone já cadastrado neste condomínio.')
        ->set('phone', '+55 41 99655-0021')
        ->call('save')
        ->assertHasNoErrors();

    expect(Resident::withoutGlobalScopes()->where('phone', '+5541996550021')->count())->toBe(2);
});

test('editing keeps the own phone and updates the resident', function () {
    $resident = Resident::factory()->owner()->for($this->condominium)->create(['name' => 'Helena', 'phone' => '+5541992011100']);
    $otherUnit = Unit::factory()->for($this->condominium)->create(['number' => '102']);
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('edit', $resident->id)
        ->assertSet('phone', '+5541992011100')
        ->assertSet('profile', ResidentProfile::PROPRIETARIO)
        ->set('name', 'Helena Barros')
        ->set('unitId', (string) $otherUnit->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($resident->fresh())
        ->name->toBe('Helena Barros')
        ->unit_id->toBe($otherUnit->id)
        ->phone->toBe('+5541992011100');
});

test('deleting a resident with a ticket is blocked', function () {
    $resident = Resident::factory()->for($this->condominium)->for($this->unit)->create();
    Ticket::factory()->for($this->condominium)->for($resident)->create();
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('delete', $resident->id)
        ->assertDispatched('toast', type: 'error', message: 'Morador com histórico: inative em vez de excluir.');

    expect(Resident::find($resident->id))->not->toBeNull();
});

test('deleting a resident with a reservation or escalation is blocked', function (string $model) {
    $resident = Resident::factory()->for($this->condominium)->for($this->unit)->create();
    $model::factory()->for($this->condominium)->for($resident)->create();
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('delete', $resident->id)
        ->assertDispatched('toast', type: 'error', message: 'Morador com histórico: inative em vez de excluir.');

    expect(Resident::find($resident->id))->not->toBeNull();
})->with([
    'reservation' => Reservation::class,
    'escalation' => Escalation::class,
]);

test('deleting a resident without history works', function () {
    $resident = Resident::factory()->for($this->condominium)->for($this->unit)->create();
    $toolCall = AgentToolCall::factory()->for($this->condominium)->create(['resident_id' => $resident->id]);
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('delete', $resident->id)
        ->assertDispatched('toast', type: 'success', message: 'Morador excluído.');

    expect(Resident::find($resident->id))->toBeNull()
        ->and($toolCall->fresh()->resident_id)->toBeNull()
        ->and($toolCall->fresh()->phone)->toBe($resident->phone);
});

test('deactivating writes is_active false', function () {
    $resident = Resident::factory()->for($this->condominium)->for($this->unit)->create();
    actingInPanel($this->sindico);

    $component = Livewire::test('registry.residents')->call('toggleActive', $resident->id);

    expect($resident->fresh()->is_active)->toBeFalse();

    $component->call('toggleActive', $resident->id);

    expect($resident->fresh()->is_active)->toBeTrue();
});

test('resident can be created inactive from the modal', function () {
    actingInPanel($this->sindico);

    Livewire::test('registry.residents')
        ->call('create')
        ->fill(residentForm(['isActive' => false]))
        ->call('save')
        ->assertHasNoErrors();

    expect(Resident::sole()->is_active)->toBeFalse();
});

test('a resident of another condominium cannot be changed', function () {
    $otherResident = Resident::factory()->create();
    actingInPanel($this->sindico);

    expect(fn () => Livewire::test('registry.residents')->call('toggleActive', $otherResident->id))
        ->toThrow(ModelNotFoundException::class);

    expect($otherResident->fresh()->is_active)->toBeTrue();
});

test('zelador receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();
    $resident = Resident::factory()->for($this->condominium)->for($this->unit)->create();

    $this->actingAs($zelador)
        ->get(route('residents.index'))
        ->assertForbidden();

    actingInPanel($zelador);

    Livewire::test('registry.residents')->assertForbidden();

    expect($resident->fresh()->is_active)->toBeTrue();
});
