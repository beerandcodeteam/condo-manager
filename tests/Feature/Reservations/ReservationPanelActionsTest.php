<?php

use App\Jobs\SendWebhookDelivery;
use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\ReservationCancellationOrigin;
use App\Models\ReservationOrigin;
use App\Models\ReservationStatus;
use App\Models\Resident;
use App\Models\Unit;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Support\Tenancy\CurrentCondominium;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Sao_Paulo'));

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();

    $this->area = CommonArea::factory()->for($this->condominium)->create(['name' => 'Salão de festas', 'min_advance_hours' => 24]);
    $this->slot = CommonAreaSlot::factory()->for($this->area, 'area')->create(['starts_at' => '19:00:00', 'ends_at' => '23:00:00']);
    $this->unit = Unit::factory()->for($this->condominium)->create(['number' => '402']);
    $this->resident = Resident::factory()->for($this->unit)->create(['condominium_id' => $this->condominium->id, 'name' => 'Carla Souza', 'phone' => '+5511999990000']);
});

function manualReservationForm(array $overrides = []): Testable
{
    $data = [
        'manualAreaId' => (string) test()->area->id,
        'manualDate' => '2026-09-15',
        'manualSlotId' => (string) test()->slot->id,
        'manualUnitId' => (string) test()->unit->id,
        'manualResidentId' => (string) test()->resident->id,
        ...$overrides,
    ];

    $component = Livewire::test('pages::reservations')->call('createManual');

    foreach ($data as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

test('manual reservation inside the minimum advance is created with origin painel and the creator', function () {
    actingInPanel($this->sindico);

    manualReservationForm()
        ->call('saveManual')
        ->assertHasNoErrors()
        ->assertSet('showManualForm', false)
        ->assertDispatched('toast', type: 'success', message: 'Reserva criada.');

    expect(Reservation::query()->sole())
        ->common_area_slot_id->toBe($this->slot->id)
        ->unit_id->toBe($this->unit->id)
        ->resident_id->toBe($this->resident->id)
        ->reservation_status_id->toBe(ReservationStatus::idFor(ReservationStatus::CONFIRMADA))
        ->reservation_origin_id->toBe(ReservationOrigin::idFor(ReservationOrigin::PAINEL))
        ->created_by_user_id->toBe($this->sindico->id)
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('taken slot shows up disabled and saving it gives an error', function () {
    Reservation::factory()->for($this->slot, 'slot')->create(['date' => '2026-09-20']);
    actingInPanel($this->sindico);

    $component = manualReservationForm(['manualDate' => '2026-09-20']);

    expect($component->instance()->manualSlots)->toBe([['id' => $this->slot->id, 'label' => '19:00–23:00', 'taken' => true]]);

    $component
        ->call('saveManual')
        ->assertHasErrors(['manualSlotId'])
        ->assertSee('Faixa já reservada nesta data');

    expect(Reservation::query()->count())->toBe(1);
});

test('past date gives an error', function () {
    actingInPanel($this->sindico);

    manualReservationForm(['manualDate' => '2026-09-14'])
        ->call('saveManual')
        ->assertHasErrors(['manualDate'])
        ->assertSee('Não é possível reservar uma data passada.');

    expect(Reservation::query()->count())->toBe(0);
});

test('resident of another unit gives an error', function () {
    $otherResident = Resident::factory()->for($this->condominium)->create();
    actingInPanel($this->sindico);

    manualReservationForm(['manualResidentId' => (string) $otherResident->id])
        ->call('saveManual')
        ->assertHasErrors(['manualResidentId'])
        ->assertSee('Escolha um morador ativo da unidade selecionada.');

    expect(Reservation::query()->count())->toBe(0);
});

test('inactive area or slot of another area gives an error', function () {
    $otherSlot = CommonAreaSlot::factory()->for(CommonArea::factory()->for($this->condominium), 'area')->create();
    actingInPanel($this->sindico);

    manualReservationForm(['manualSlotId' => (string) $otherSlot->id])
        ->call('saveManual')
        ->assertHasErrors(['manualSlotId']);

    $this->area->update(['is_active' => false]);

    manualReservationForm()
        ->call('saveManual')
        ->assertHasErrors(['manualAreaId']);

    expect(Reservation::query()->count())->toBe(0);
});

test('cancelling requires a reason and creates a webhook delivery', function () {
    $reservation = Reservation::factory()->for($this->slot, 'slot')->for($this->resident)->create(['date' => '2026-09-15']);
    actingInPanel($this->sindico);

    $component = Livewire::test('pages::reservations')
        ->assertSee('data-reservation-chip="'.$reservation->id.'"', false)
        ->call('openReservation', $reservation->id)
        ->call('promptCancel')
        ->assertSet('showCancelForm', true)
        ->set('cancelReason', '   ')
        ->call('cancelReservation')
        ->assertHasErrors(['cancelReason'])
        ->assertSee('Informe o motivo do cancelamento.');

    expect($reservation->fresh()->cancelled_at)->toBeNull()
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);

    $component
        ->set('cancelReason', 'Manutenção elétrica')
        ->call('cancelReservation')
        ->assertHasNoErrors()
        ->assertSet('showCancelForm', false)
        ->assertSet('showDetails', false)
        ->assertDispatched('toast', type: 'success', message: 'Reserva cancelada. O morador será avisado.')
        ->assertDontSee('data-reservation-chip="'.$reservation->id.'"', false);

    expect($reservation->fresh())
        ->reservation_cancellation_origin_id->toBe(ReservationCancellationOrigin::idFor(ReservationCancellationOrigin::SINDICO))
        ->cancelled_by_user_id->toBe($this->sindico->id)
        ->cancellation_reason->toBe('Manutenção elétrica');

    $delivery = WebhookDelivery::query()->withoutGlobalScopes()->sole();

    expect($delivery->webhook_event_id)->toBe(WebhookEvent::idFor(WebhookEvent::RESERVATION_CANCELLED))
        ->and($delivery->payload['data']['reason'])->toBe('Manutenção elétrica');

    Queue::assertPushed(SendWebhookDelivery::class);
});

test('past reservation offers no cancel action and cannot be cancelled', function () {
    $reservation = Reservation::factory()->for($this->slot, 'slot')->for($this->resident)->create(['date' => '2026-09-14']);
    actingInPanel($this->sindico);

    Livewire::test('pages::reservations')
        ->call('openReservation', $reservation->id)
        ->assertDontSee('data-cancel-reservation', false)
        ->call('promptCancel')
        ->set('cancelReason', 'Motivo')
        ->call('cancelReservation')
        ->assertDispatched('toast', type: 'error', message: 'Reservas de datas passadas não podem ser canceladas.');

    expect($reservation->fresh()->cancelled_at)->toBeNull();
});

test('manual reservation appears in the resident reservations API', function () {
    actingInPanel($this->sindico);

    manualReservationForm(['manualDate' => '2026-09-27'])
        ->call('saveManual')
        ->assertHasNoErrors();

    $reservation = Reservation::query()->sole();

    app(CurrentCondominium::class)->clear();
    app('auth')->forgetGuards();

    $this->withToken($this->condominium->createToken('n8n')->plainTextToken)
        ->getJson(route('api.v1.reservations_list', ['phone' => '+5511999990000']))
        ->assertOk()
        ->assertExactJson([
            'reservations' => [
                ['id' => $reservation->id, 'area' => 'Salão de festas', 'date' => '2026-09-27', 'starts' => '19:00', 'ends' => '23:00', 'resident' => 'Carla Souza', 'cancellable' => true],
            ],
        ]);
});
