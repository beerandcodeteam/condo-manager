<?php

use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\CurrentCondominium;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Sao_Paulo'));

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
});

function areaWithSlots(Condominium $condominium, array $attributes = [], array $slots = [['10:00:00', '16:00:00']]): CommonArea
{
    $area = CommonArea::factory()->for($condominium)->create($attributes);

    foreach ($slots as [$startsAt, $endsAt]) {
        CommonAreaSlot::factory()->for($area, 'area')->create(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    return $area;
}

test('settings card lists each area with its rules summary and edit link', function () {
    areaWithSlots($this->condominium, ['name' => 'Churrasqueira', 'min_advance_hours' => 48, 'max_advance_days' => 30, 'cancellation_deadline_hours' => 12]);
    areaWithSlots($this->condominium, ['name' => 'Salão de festas']);
    CommonArea::factory()->create(['name' => 'Piscina']);

    $this->actingAs($this->sindico)
        ->get(route('settings'))
        ->assertOk()
        ->assertSeeInOrder(['Áreas comuns', '+ Adicionar'])
        ->assertSeeInOrder(['Churrasqueira', '48 h · até 30 dias · cancela até 12 h', 'Editar', 'Salão de festas', '24 h · até 60 dias · cancela até 24 h', 'Editar'])
        ->assertDontSee('Piscina');
});

test('new area form starts with the 24/60/24 defaults and saves them', function () {
    actingInPanel($this->sindico);

    Livewire::test('settings.common-areas')
        ->call('create')
        ->assertSet('minAdvanceHours', '24')
        ->assertSet('maxAdvanceDays', '60')
        ->assertSet('cancellationDeadlineHours', '24')
        ->assertSet('isActive', true)
        ->set('name', 'Churrasqueira')
        ->set('description', 'Coberta, no térreo')
        ->set('areaSlots', [['id' => null, 'starts' => '17:00', 'ends' => '23:00'], ['id' => null, 'starts' => '10:00', 'ends' => '16:00']])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success', message: 'Área criada.');

    $area = CommonArea::query()->sole();

    expect($area)
        ->condominium_id->toBe($this->condominium->id)
        ->name->toBe('Churrasqueira')
        ->is_active->toBeTrue()
        ->min_advance_hours->toBe(24)
        ->max_advance_days->toBe(60)
        ->cancellation_deadline_hours->toBe(24)
        ->and($area->slots->map(fn (CommonAreaSlot $slot) => [$slot->starts_at, $slot->ends_at])->all())->toBe([['10:00:00', '16:00:00'], ['17:00:00', '23:00:00']])
        ->and($area->slots->every(fn (CommonAreaSlot $slot) => $slot->condominium_id === $this->condominium->id))->toBeTrue();
});

test('overlapping slots are rejected', function () {
    actingInPanel($this->sindico);

    Livewire::test('settings.common-areas')
        ->call('create')
        ->set('name', 'Churrasqueira')
        ->set('areaSlots', [['id' => null, 'starts' => '10:00', 'ends' => '16:00'], ['id' => null, 'starts' => '15:00', 'ends' => '20:00']])
        ->call('save')
        ->assertHasErrors(['areaSlots'])
        ->assertSee('As faixas 10:00–16:00 e 15:00–20:00 se sobrepõem.');

    expect(CommonArea::query()->count())->toBe(0);
});

test('slot starting at or after its end is rejected', function (string $starts, string $ends) {
    actingInPanel($this->sindico);

    Livewire::test('settings.common-areas')
        ->call('create')
        ->set('name', 'Churrasqueira')
        ->set('areaSlots', [['id' => null, 'starts' => $starts, 'ends' => $ends]])
        ->call('save')
        ->assertHasErrors(['areaSlots.0.ends'])
        ->assertSee('O início da faixa deve ser antes do fim.');

    expect(CommonArea::query()->count())->toBe(0);
})->with([['16:00', '16:00'], ['18:00', '10:00']]);

test('activating an area without slots is rejected, but an inactive area may have none', function () {
    actingInPanel($this->sindico);

    Livewire::test('settings.common-areas')
        ->call('create')
        ->set('name', 'Quadra')
        ->set('areaSlots', [])
        ->call('save')
        ->assertHasErrors(['areaSlots'])
        ->assertSee('Área ativa precisa de pelo menos uma faixa de horário.')
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(CommonArea::query()->sole()->is_active)->toBeFalse();
});

test('rules must be integers within their limits', function () {
    actingInPanel($this->sindico);

    Livewire::test('settings.common-areas')
        ->call('create')
        ->set('name', 'Quadra')
        ->set('minAdvanceHours', '-1')
        ->set('maxAdvanceDays', '0')
        ->set('cancellationDeadlineHours', '2.5')
        ->call('save')
        ->assertHasErrors(['minAdvanceHours', 'maxAdvanceDays', 'cancellationDeadlineHours']);

    expect(CommonArea::query()->count())->toBe(0);
});

test('removing or changing a slot with a future reservation is blocked and lists the dates', function (string $change) {
    $area = areaWithSlots($this->condominium, ['name' => 'Churrasqueira'], [['10:00:00', '16:00:00'], ['17:00:00', '23:00:00']]);
    [$daySlot, $nightSlot] = $area->slots->all();
    Reservation::factory()->for($nightSlot, 'slot')->create(['date' => '2026-09-27']);
    Reservation::factory()->for($nightSlot, 'slot')->create(['date' => '2026-09-15']);
    Reservation::factory()->cancelled()->for($nightSlot, 'slot')->create(['date' => '2026-09-30']);
    actingInPanel($this->sindico);

    $slots = [['id' => $daySlot->id, 'starts' => '10:00', 'ends' => '16:00']];

    if ($change === 'alterar') {
        $slots[] = ['id' => $nightSlot->id, 'starts' => '18:00', 'ends' => '23:00'];
    }

    Livewire::test('settings.common-areas')
        ->call('edit', $area->id)
        ->set('areaSlots', $slots)
        ->call('save')
        ->assertHasErrors(['areaSlots'])
        ->assertSee($change === 'alterar'
            ? 'A faixa 17:00–23:00 tem reservas futuras em 15/09/2026, 27/09/2026 e não pode ter o horário alterado.'
            : 'A faixa 17:00–23:00 tem reservas futuras em 15/09/2026, 27/09/2026 e não pode ser removida.');

    expect($nightSlot->fresh())
        ->deleted_at->toBeNull()
        ->starts_at->toBe('17:00:00');
})->with(['remover', 'alterar']);

test('removing a slot with only past reservations soft deletes it', function () {
    $area = areaWithSlots($this->condominium, ['name' => 'Churrasqueira'], [['10:00:00', '16:00:00'], ['17:00:00', '23:00:00']]);
    [$daySlot, $nightSlot] = $area->slots->all();
    $pastReservation = Reservation::factory()->for($nightSlot, 'slot')->create(['date' => '2026-09-14']);
    actingInPanel($this->sindico);

    Livewire::test('settings.common-areas')
        ->call('edit', $area->id)
        ->assertSet('areaSlots', [
            ['id' => $daySlot->id, 'starts' => '10:00', 'ends' => '16:00'],
            ['id' => $nightSlot->id, 'starts' => '17:00', 'ends' => '23:00'],
        ])
        ->call('removeSlot', 1)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success', message: 'Área atualizada.');

    expect(CommonAreaSlot::query()->find($nightSlot->id))->toBeNull()
        ->and(CommonAreaSlot::withTrashed()->find($nightSlot->id)->trashed())->toBeTrue()
        ->and($pastReservation->fresh()->slot->id)->toBe($nightSlot->id)
        ->and($area->slots()->pluck('id')->all())->toBe([$daySlot->id]);
});

test('duplicated name in the condominium is rejected', function () {
    areaWithSlots($this->condominium, ['name' => 'Churrasqueira']);
    areaWithSlots(Condominium::factory()->create(), ['name' => 'Salão de festas']);
    actingInPanel($this->sindico);

    Livewire::test('settings.common-areas')
        ->call('create')
        ->set('name', 'Churrasqueira')
        ->set('areaSlots', [['id' => null, 'starts' => '10:00', 'ends' => '16:00']])
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('Já existe uma área com este nome.')
        ->set('name', 'Salão de festas')
        ->call('save')
        ->assertHasNoErrors();

    expect(CommonArea::query()->where('condominium_id', $this->condominium->id)->count())->toBe(2);
});

test('zelador receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();

    actingInPanel($zelador);

    Livewire::test('settings.common-areas')->assertForbidden();
});

test('a deactivated area disappears from the API', function () {
    $area = areaWithSlots($this->condominium, ['name' => 'Churrasqueira']);
    $token = $this->condominium->createToken('n8n')->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.areas_list'))->assertJsonCount(1, 'areas');

    actingInPanel($this->sindico);

    Livewire::test('settings.common-areas')
        ->call('edit', $area->id)
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($area->fresh()->is_active)->toBeFalse();

    app(CurrentCondominium::class)->clear();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson(route('api.v1.areas_list'))->assertOk()->assertExactJson(['areas' => []]);
    $this->withToken($token)
        ->getJson(route('api.v1.areas_availability', ['area' => $area->id, 'date' => '2026-09-20']))
        ->assertNotFound()
        ->assertJsonPath('code', 'area_not_found');
});
