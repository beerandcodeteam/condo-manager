<?php

use App\Exceptions\Api\ApiException;
use App\Exceptions\ReservationActionBlockedException;
use App\Jobs\SendWebhookDelivery;
use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\ReservationCancellationOrigin;
use App\Models\ReservationStatus;
use App\Models\Resident;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Reservations\ReservationService;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Sao_Paulo'));

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->area = CommonArea::factory()->for($this->condominium)->create(['name' => 'Salão de festas', 'cancellation_deadline_hours' => 24]);
    $this->slot = CommonAreaSlot::factory()->for($this->area, 'area')->create(['starts_at' => '19:00:00', 'ends_at' => '23:00:00']);
    $this->resident = Resident::factory()->for($this->condominium)->create(['phone' => '+5511999990000']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
    $this->service = app(ReservationService::class);
});

function reservationFor(Resident $resident, CommonAreaSlot $slot, string $date): Reservation
{
    return Reservation::factory()->for($slot, 'slot')->for($resident)->create(['date' => $date]);
}

function expectReservationApiError(string $code, Closure $callback): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe($code);

        return;
    }

    test()->fail("Expected API error [{$code}].");
}

test('resident cancels within the deadline', function () {
    $reservation = reservationFor($this->resident, $this->slot, '2026-09-20');
    $housemate = Resident::factory()->for($this->resident->unit)->create(['condominium_id' => $this->condominium->id]);

    $this->service->cancelByResident($reservation, $housemate);

    expect($reservation->fresh())
        ->reservation_status_id->toBe(ReservationStatus::idFor(ReservationStatus::CANCELADA))
        ->reservation_cancellation_origin_id->toBe(ReservationCancellationOrigin::idFor(ReservationCancellationOrigin::MORADOR))
        ->cancelled_by_user_id->toBeNull()
        ->cancellation_reason->toBeNull()
        ->and($reservation->fresh()->cancelled_at)->not->toBeNull()
        ->and($this->service->isSlotTaken($this->slot, '2026-09-20'))->toBeFalse();
});

test('resident cancelling after the deadline gets cancellation_deadline_passed', function () {
    $reservation = reservationFor($this->resident, $this->slot, '2026-09-16');
    $this->travelTo(CarbonImmutable::parse('2026-09-15 19:00:01', 'America/Sao_Paulo'));

    expectReservationApiError('cancellation_deadline_passed', fn () => $this->service->cancelByResident($reservation, $this->resident));

    expect($reservation->fresh()->cancelled_at)->toBeNull();
});

test('reservation of another unit or already cancelled gets reservation_not_found', function () {
    $otherUnitReservation = reservationFor(Resident::factory()->for($this->condominium)->create(), $this->slot, '2026-09-20');
    $cancelledReservation = Reservation::factory()->cancelled()->for($this->slot, 'slot')->for($this->resident)->create(['date' => '2026-09-21']);

    expectReservationApiError('reservation_not_found', fn () => $this->service->cancelByResident($otherUnitReservation, $this->resident));
    expectReservationApiError('reservation_not_found', fn () => $this->service->cancelByResident($cancelledReservation, $this->resident));

    expect($otherUnitReservation->fresh()->cancelled_at)->toBeNull();
});

test('syndic cancels inside the resident deadline recording origin, user and reason', function () {
    $reservation = reservationFor($this->resident, $this->slot, '2026-09-15');

    $this->service->cancelBySyndic($reservation, '  Manutenção elétrica no salão  ', $this->sindico);

    expect($reservation->fresh())
        ->reservation_status_id->toBe(ReservationStatus::idFor(ReservationStatus::CANCELADA))
        ->reservation_cancellation_origin_id->toBe(ReservationCancellationOrigin::idFor(ReservationCancellationOrigin::SINDICO))
        ->cancelled_by_user_id->toBe($this->sindico->id)
        ->cancellation_reason->toBe('Manutenção elétrica no salão')
        ->and($reservation->fresh()->cancelled_at)->not->toBeNull();
});

test('syndic cannot cancel a past reservation', function () {
    $reservation = reservationFor($this->resident, $this->slot, '2026-09-14');

    expect(fn () => $this->service->cancelBySyndic($reservation, 'Motivo', $this->sindico))
        ->toThrow(ReservationActionBlockedException::class);

    expect($reservation->fresh()->cancelled_at)->toBeNull();
});

test('syndic must give a reason', function () {
    $reservation = reservationFor($this->resident, $this->slot, '2026-09-20');

    expect(fn () => $this->service->cancelBySyndic($reservation, '   ', $this->sindico))
        ->toThrow(ValidationException::class, 'Informe o motivo do cancelamento.');

    expect($reservation->fresh()->cancelled_at)->toBeNull();
});

test('only the syndic cancellation dispatches the webhook', function () {
    $residentCancelled = reservationFor($this->resident, $this->slot, '2026-09-20');
    $syndicCancelled = reservationFor($this->resident, $this->slot, '2026-09-27');

    $this->service->cancelByResident($residentCancelled, $this->resident);

    expect(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->service->cancelBySyndic($syndicCancelled, 'Uso indevido', $this->sindico);

    $delivery = WebhookDelivery::query()->withoutGlobalScopes()->sole();

    expect($delivery->webhook_event_id)->toBe(WebhookEvent::idFor(WebhookEvent::RESERVATION_CANCELLED))
        ->and($delivery->subject_id)->toBe($syndicCancelled->id)
        ->and($delivery->resident_phone)->toBe('+5511999990000')
        ->and($delivery->payload['event'])->toBe('reservation.cancelled')
        ->and($delivery->payload['data'])->toEqual([
            'reservation_id' => $syndicCancelled->id,
            'area' => 'Salão de festas',
            'date' => '2026-09-27',
            'starts' => '19:00',
            'ends' => '23:00',
            'reason' => 'Uso indevido',
        ]);

    Queue::assertPushed(SendWebhookDelivery::class, 1);
});
