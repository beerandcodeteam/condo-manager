<?php

use App\Models\Block;
use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\ReservationCancellationOrigin;
use App\Models\Resident;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Sao_Paulo'));

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();

    $this->salao = CommonArea::factory()->for($this->condominium)->create(['name' => 'Salão de festas', 'description' => 'Salão do térreo']);
    $this->salaoSlot = CommonAreaSlot::factory()->for($this->salao, 'area')->create(['starts_at' => '19:00:00', 'ends_at' => '23:00:00']);
    $this->churrasqueira = CommonArea::factory()->for($this->condominium)->create(['name' => 'Churrasqueira', 'min_advance_hours' => 48]);
    $this->churrasqueiraSlot = CommonAreaSlot::factory()->for($this->churrasqueira, 'area')->create(['starts_at' => '12:00:00', 'ends_at' => '18:00:00']);

    $unit = Unit::factory()->for(Block::factory()->for($this->condominium)->state(['name' => 'B']))->create(['number' => '402']);
    $this->resident = Resident::factory()->for($unit)->create(['name' => 'Carla Souza', 'condominium_id' => $this->condominium->id]);
});

test('week goes from Monday to Sunday with today highlighted and a manual reservation button', function () {
    $this->actingAs($this->sindico)
        ->get(route('reservations.index'))
        ->assertOk()
        ->assertSeeInOrder(['14 – 20 set 2026', '+ Reserva manual'])
        ->assertSeeInOrder(['seg 14', 'ter 15', 'qua 16', 'qui 17', 'sex 18', 'sáb 19', 'dom 20'])
        ->assertSeeInOrder(['data-day="2026-09-15"', 'data-today', 'data-day="2026-09-16"'], false)
        ->assertSeeInOrder(['Churrasqueira', 'Salão de festas', 'Salão do térreo']);

    actingInPanel($this->sindico);

    $component = Livewire::test('pages::reservations');

    expect(collect($component->instance()->days)->pluck('date')->all())->toBe([
        '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20',
    ])
        ->and(collect($component->instance()->days)->where('is_today', true)->pluck('date')->all())->toBe(['2026-09-15']);
});

test('on Sunday night the week is still the São Paulo week', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 01:30:00', 'UTC'));
    actingInPanel($this->sindico);

    Livewire::test('pages::reservations')
        ->assertSee('14 – 20 set 2026')
        ->assertSee('dom 20');
});

test('grid shows not cancelled reservations of active areas with origin colors, without cancelled ones', function () {
    $whatsapp = Reservation::factory()->for($this->salaoSlot, 'slot')->for($this->resident)->create(['date' => '2026-09-18']);
    $manual = Reservation::factory()->manual()->for($this->churrasqueiraSlot, 'slot')->for($this->resident)->create(['date' => '2026-09-19']);
    $cancelled = Reservation::factory()->cancelled()->for($this->churrasqueiraSlot, 'slot')->for($this->resident)->create(['date' => '2026-09-20']);
    $nextWeek = Reservation::factory()->for($this->salaoSlot, 'slot')->for($this->resident)->create(['date' => '2026-09-21']);
    $inactiveArea = CommonArea::factory()->inactive()->for($this->condominium)->create(['name' => 'Quadra antiga']);
    Reservation::factory()->manual()->for(CommonAreaSlot::factory()->for($inactiveArea, 'area'), 'slot')->for($this->resident)->create(['date' => '2026-09-18']);

    actingInPanel($this->sindico);

    Livewire::test('pages::reservations')
        ->assertSee('data-reservation-chip="'.$whatsapp->id.'"', false)
        ->assertSee('data-reservation-chip="'.$manual->id.'"', false)
        ->assertDontSee('data-reservation-chip="'.$cancelled->id.'"', false)
        ->assertDontSee('data-reservation-chip="'.$nextWeek->id.'"', false)
        ->assertDontSee('Quadra antiga')
        ->assertSeeInOrder(['data-cell="'.$this->salao->id.'-2026-09-18"', 'bg-tag-ok-bg', '19h–23h', 'Souza · 402B'], false)
        ->assertSeeInOrder(['data-cell="'.$this->churrasqueira->id.'-2026-09-19"', 'bg-tag-ia-bg', '12h–18h', 'Souza · 402B'], false);
});

test('clicking a chip shows the reservation details with the cancel action', function () {
    $reservation = Reservation::factory()->for($this->salaoSlot, 'slot')->for($this->resident)->create(['date' => '2026-09-18']);
    actingInPanel($this->sindico);

    $component = Livewire::test('pages::reservations')
        ->call('openReservation', $reservation->id)
        ->assertSet('showDetails', true);

    TestResponse::fromBaseResponse(response($component->html()))
        ->assertSeeInOrder(['data-reservation-details', '18/09/2026', '19:00–23:00', 'Salão de festas', '402B', 'Carla Souza', 'WhatsApp (agente)', 'Cancelar reserva'], false);
});

test('navigates to the next and previous weeks', function () {
    $nextWeek = Reservation::factory()->for($this->salaoSlot, 'slot')->for($this->resident)->create(['date' => '2026-09-21']);
    actingInPanel($this->sindico);

    Livewire::test('pages::reservations')
        ->call('nextWeek')
        ->assertSet('week', '2026-09-21')
        ->assertSee('21 – 27 set 2026')
        ->assertSee('seg 21')
        ->assertSee('data-reservation-chip="'.$nextWeek->id.'"', false)
        ->call('nextWeek')
        ->assertSee('28 set – 4 out 2026')
        ->call('previousWeek')
        ->call('previousWeek')
        ->assertSet('week', '')
        ->assertSee('14 – 20 set 2026');

    $this->actingAs($this->sindico)
        ->get(route('reservations.index', ['semana' => '2026-09-23']))
        ->assertOk()
        ->assertSee('21 – 27 set 2026');
});

test('WhatsApp card lists the latest 10 WhatsApp reservations only, with status and cancellation note', function () {
    foreach (range(1, 11) as $day) {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 08:00:00', 'America/Sao_Paulo')->addMinutes($day));
        Reservation::factory()->for($this->salaoSlot, 'slot')->for($this->resident)->create(['date' => CarbonImmutable::parse('2026-09-20')->addDays($day)->toDateString()]);
    }

    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00', 'America/Sao_Paulo'));
    $manual = Reservation::factory()->manual()->for($this->churrasqueiraSlot, 'slot')->for($this->resident)->create(['date' => '2026-09-26']);
    $bySyndic = Reservation::factory()->cancelled()->for($this->churrasqueiraSlot, 'slot')->for($this->resident)->create([
        'date' => '2026-09-19',
        'reservation_cancellation_origin_id' => ReservationCancellationOrigin::idFor(ReservationCancellationOrigin::SINDICO),
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:30:00', 'America/Sao_Paulo'));
    $byResident = Reservation::factory()->cancelled()->for($this->salaoSlot, 'slot')->for($this->resident)->create(['date' => '2026-09-18']);
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Sao_Paulo'));

    actingInPanel($this->sindico);

    $component = Livewire::test('pages::reservations');

    expect($component->instance()->whatsappReservations)->toHaveCount(10)
        ->and($component->instance()->whatsappReservations->pluck('id')->contains($manual->id))->toBeFalse();

    $component
        ->assertSeeInOrder([
            'Pedidos pelo WhatsApp',
            'data-whatsapp-request="'.$byResident->id.'"', 'Carla Souza · 402B', 'Cancelada', 'Salão de festas · sex 18/09 · 19h–23h', 'Cancelada pelo morador',
            'data-whatsapp-request="'.$bySyndic->id.'"', 'Cancelada', 'Churrasqueira · sáb 19/09 · 12h–18h', 'Cancelada pelo síndico',
            'Confirmada',
        ], false)
        ->assertSeeText('O agente só confirma se reservar_area aceitar.')
        ->assertSeeInOrder(['Regras aplicadas pela tool', 'Churrasqueira', '12h–18h · 48 h · até 60 dias · cancela até 24 h', 'Salão de festas', '19h–23h · 24 h · até 60 dias · cancela até 24 h']);
});

test('zelador receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();

    $this->actingAs($zelador)
        ->get(route('reservations.index'))
        ->assertForbidden();

    actingInPanel($zelador);

    Livewire::test('pages::reservations')->assertForbidden();
});
