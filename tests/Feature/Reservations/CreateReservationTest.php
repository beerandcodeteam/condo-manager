<?php

use App\Exceptions\Api\AdvanceNoticeViolation;
use App\Exceptions\Api\ApiException;
use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\ReservationOrigin;
use App\Models\ReservationStatus;
use App\Models\Resident;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Reservations\ReservationService;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Sao_Paulo'));

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->area = CommonArea::factory()->for($this->condominium)->create(['name' => 'Churrasqueira']);
    $this->slot = CommonAreaSlot::factory()->for($this->area, 'area')->create(['starts_at' => '17:00:00', 'ends_at' => '23:00:00']);
    $this->resident = Resident::factory()->for($this->condominium)->create();
    $this->service = app(ReservationService::class);
});

/**
 * Run the callback expecting an ApiException with the given code; returns the exception.
 */
function expectApiError(string $code, Closure $callback): ApiException
{
    try {
        $callback();
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe($code);

        return $exception;
    }

    test()->fail("Expected API error [{$code}].");
}

test('creates a confirmed WhatsApp reservation for the resident unit', function () {
    $reservation = $this->service->create($this->area, $this->slot, '2026-09-27', $this->resident, ReservationOrigin::WHATSAPP);

    expect($reservation->fresh())
        ->condominium_id->toBe($this->condominium->id)
        ->common_area_id->toBe($this->area->id)
        ->common_area_slot_id->toBe($this->slot->id)
        ->resident_id->toBe($this->resident->id)
        ->unit_id->toBe($this->resident->unit_id)
        ->reservation_status_id->toBe(ReservationStatus::idFor(ReservationStatus::CONFIRMADA))
        ->reservation_origin_id->toBe(ReservationOrigin::idFor(ReservationOrigin::WHATSAPP))
        ->created_by_user_id->toBeNull()
        ->cancelled_at->toBeNull()
        ->and($reservation->fresh()->date->format('Y-m-d'))->toBe('2026-09-27');
});

test('inactive area is refused with area_unavailable', function () {
    $this->area->update(['is_active' => false]);

    expectApiError('area_unavailable', fn () => $this->service->create($this->area, $this->slot, '2026-09-27', $this->resident, ReservationOrigin::WHATSAPP));

    expect(Reservation::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('slot of another area is refused with area_unavailable', function () {
    $otherSlot = CommonAreaSlot::factory()->for(CommonArea::factory()->for($this->condominium), 'area')->create();

    expectApiError('area_unavailable', fn () => $this->service->create($this->area, $otherSlot, '2026-09-27', $this->resident, ReservationOrigin::WHATSAPP));

    expect(Reservation::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('deleted slot is refused with area_unavailable', function () {
    $this->slot->delete();

    expectApiError('area_unavailable', fn () => $this->service->create($this->area, $this->slot, '2026-09-27', $this->resident, ReservationOrigin::WHATSAPP));
});

test('inside the minimum advance is refused with advance_notice_violation and the limits', function () {
    $exception = expectApiError('advance_notice_violation', fn () => $this->service->create($this->area, $this->slot, '2026-09-15', $this->resident, ReservationOrigin::WHATSAPP));

    expect($exception)->toBeInstanceOf(AdvanceNoticeViolation::class)
        ->and($exception->status)->toBe(422)
        ->and($exception->extras)->toBe(['min_advance_hours' => 24, 'max_advance_days' => 60])
        ->and(Reservation::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('beyond the maximum advance is refused with advance_notice_violation and the limits', function () {
    $exception = expectApiError('advance_notice_violation', fn () => $this->service->create($this->area, $this->slot, '2026-11-15', $this->resident, ReservationOrigin::WHATSAPP));

    expect($exception->extras)->toBe(['min_advance_hours' => 24, 'max_advance_days' => 60])
        ->and(Reservation::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('taken slot is refused with slot_unavailable', function () {
    Reservation::factory()->for($this->slot, 'slot')->create(['date' => '2026-09-27']);

    expectApiError('slot_unavailable', fn () => $this->service->create($this->area, $this->slot, '2026-09-27', $this->resident, ReservationOrigin::WHATSAPP));

    expect(Reservation::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('a concurrent reservation inserted after the prior check results in slot_unavailable and a single row', function () {
    $competitor = Resident::factory()->for($this->condominium)->create();
    $hasInsertedCompetitor = false;

    DB::connection()->beforeStartingTransaction(function () use (&$hasInsertedCompetitor, $competitor): void {
        if ($hasInsertedCompetitor) {
            return;
        }

        $hasInsertedCompetitor = true;

        DB::table('reservations')->insert([
            'condominium_id' => $this->condominium->id,
            'common_area_id' => $this->area->id,
            'common_area_slot_id' => $this->slot->id,
            'unit_id' => $competitor->unit_id,
            'resident_id' => $competitor->id,
            'reservation_status_id' => ReservationStatus::idFor(ReservationStatus::CONFIRMADA),
            'reservation_origin_id' => ReservationOrigin::idFor(ReservationOrigin::WHATSAPP),
            'date' => '2026-09-27',
            'starts_at' => '17:00:00',
            'ends_at' => '23:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expectApiError('slot_unavailable', fn () => $this->service->create($this->area, $this->slot, '2026-09-27', $this->resident, ReservationOrigin::WHATSAPP));

    expect($hasInsertedCompetitor)->toBeTrue()
        ->and(Reservation::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(Reservation::query()->withoutGlobalScopes()->sole()->resident_id)->toBe($competitor->id);
});

test('panel reservation ignores the advance window but refuses a past date', function () {
    $sindico = User::factory()->sindico()->for($this->condominium)->create();

    $reservation = $this->service->create($this->area, $this->slot, '2026-09-15', $this->resident, ReservationOrigin::PAINEL, $sindico);

    expect($reservation->fresh())
        ->reservation_origin_id->toBe(ReservationOrigin::idFor(ReservationOrigin::PAINEL))
        ->created_by_user_id->toBe($sindico->id);

    $farReservation = $this->service->create($this->area, $this->slot, '2026-12-20', $this->resident, ReservationOrigin::PAINEL, $sindico);

    expect($farReservation->exists)->toBeTrue();

    expect(fn () => $this->service->create($this->area, $this->slot, '2026-09-14', $this->resident, ReservationOrigin::PAINEL, $sindico))
        ->toThrow(ValidationException::class, 'Não é possível reservar uma data passada.');

    expect(Reservation::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('slot times are copied as a snapshot', function () {
    $reservation = $this->service->create($this->area, $this->slot, '2026-09-27', $this->resident, ReservationOrigin::WHATSAPP);

    $this->slot->update(['starts_at' => '18:00:00', 'ends_at' => '22:00:00']);

    expect($reservation->fresh())
        ->starts_at->toBe('17:00:00')
        ->ends_at->toBe('23:00:00');
});

test('creating reservations dispatches no webhook', function () {
    $sindico = User::factory()->sindico()->for($this->condominium)->create();

    $this->service->create($this->area, $this->slot, '2026-09-27', $this->resident, ReservationOrigin::WHATSAPP);
    $this->service->create($this->area, $this->slot, '2026-09-28', $this->resident, ReservationOrigin::PAINEL, $sindico);

    expect(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);

    Queue::assertNothingPushed();
});
