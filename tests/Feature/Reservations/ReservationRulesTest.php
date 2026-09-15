<?php

use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Services\Reservations\ReservationService;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    $this->area = CommonArea::factory()->for($this->condominium)->create([
        'min_advance_hours' => 24,
        'max_advance_days' => 60,
        'cancellation_deadline_hours' => 24,
    ]);
    $this->slot = CommonAreaSlot::factory()->for($this->area, 'area')->create(['starts_at' => '17:00:00', 'ends_at' => '23:00:00']);
    $this->service = app(ReservationService::class);
});

function travelToSaoPaulo(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, 'America/Sao_Paulo'));
}

test('slotStart combines the date and the slot start in the condominium timezone', function () {
    $start = $this->service->slotStart($this->slot, '2026-09-20');

    expect($start->format('Y-m-d H:i'))->toBe('2026-09-20 17:00')
        ->and($start->getTimezone()->getName())->toBe('America/Sao_Paulo')
        ->and($start->utc()->format('Y-m-d H:i'))->toBe('2026-09-20 20:00');
});

test('exactly the minimum advance is allowed and one minute less is not', function () {
    travelToSaoPaulo('2026-09-19 17:00:00');

    expect($this->service->withinAdvanceWindow($this->area, $this->slot, '2026-09-20'))->toBeTrue();

    travelToSaoPaulo('2026-09-19 17:01:00');

    expect($this->service->withinAdvanceWindow($this->area, $this->slot, '2026-09-20'))->toBeFalse();
});

test('today plus the maximum advance days is allowed and one more day is not', function () {
    travelToSaoPaulo('2026-09-15 10:00:00');

    expect($this->service->withinAdvanceWindow($this->area, $this->slot, '2026-11-14'))->toBeTrue()
        ->and($this->service->withinAdvanceWindow($this->area, $this->slot, '2026-11-15'))->toBeFalse();
});

test('a taken slot is unavailable', function () {
    travelToSaoPaulo('2026-09-15 10:00:00');

    Reservation::factory()->for($this->slot, 'slot')->create(['date' => '2026-09-20']);
    $otherSlot = CommonAreaSlot::factory()->for($this->area, 'area')->create(['starts_at' => '10:00:00', 'ends_at' => '16:00:00']);

    expect($this->service->isSlotTaken($this->slot, '2026-09-20'))->toBeTrue()
        ->and($this->service->isSlotTaken($this->slot, '2026-09-21'))->toBeFalse();

    $availability = collect($this->service->availability($this->area, '2026-09-20'))->keyBy('id');

    expect($availability[$this->slot->id]['available'])->toBeFalse()
        ->and($availability[$this->slot->id]['taken'])->toBeTrue()
        ->and($availability[$otherSlot->id]['available'])->toBeTrue()
        ->and($availability->keys()->all())->toBe([$otherSlot->id, $this->slot->id]);
});

test('a cancelled reservation frees the slot', function () {
    travelToSaoPaulo('2026-09-15 10:00:00');

    Reservation::factory()->cancelled()->for($this->slot, 'slot')->create(['date' => '2026-09-20']);

    expect($this->service->isSlotTaken($this->slot, '2026-09-20'))->toBeFalse()
        ->and($this->service->availability($this->area, '2026-09-20')[0]['available'])->toBeTrue();
});

test('deleted slots are not part of the availability', function () {
    travelToSaoPaulo('2026-09-15 10:00:00');

    CommonAreaSlot::factory()->for($this->area, 'area')->create(['starts_at' => '08:00:00', 'ends_at' => '09:00:00'])->delete();

    expect(collect($this->service->availability($this->area, '2026-09-20'))->pluck('id')->all())->toBe([$this->slot->id]);
});

test('slots outside the advance window are unavailable', function () {
    travelToSaoPaulo('2026-09-15 10:00:00');

    expect($this->service->availability($this->area, '2026-09-15')[0]['available'])->toBeFalse()
        ->and($this->service->availability($this->area, '2026-11-20')[0]['available'])->toBeFalse();
});

test('the 24 hour cancellation deadline is honored at the limit', function () {
    $reservation = Reservation::factory()->for($this->slot, 'slot')->create(['date' => '2026-09-20']);

    travelToSaoPaulo('2026-09-19 17:00:00');

    expect($this->service->isCancellableByResident($reservation))->toBeTrue();

    travelToSaoPaulo('2026-09-19 17:00:01');

    expect($this->service->isCancellableByResident($reservation->fresh()))->toBeFalse();
});

test('at 22:30 in São Paulo (01:30 UTC of the next day) today is still the São Paulo date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-16 01:30:00', 'UTC'));

    expect($this->service->today())->toBe('2026-09-15')
        ->and($this->service->now()->format('Y-m-d H:i'))->toBe('2026-09-15 22:30');

    $area = CommonArea::factory()->for($this->condominium)->create(['min_advance_hours' => 0, 'max_advance_days' => 1]);
    $lateSlot = CommonAreaSlot::factory()->for($area, 'area')->create(['starts_at' => '23:00:00', 'ends_at' => '23:59:00']);

    expect($this->service->withinAdvanceWindow($area, $lateSlot, '2026-09-15'))->toBeTrue()
        ->and($this->service->withinAdvanceWindow($area, $lateSlot, '2026-09-16'))->toBeTrue()
        ->and($this->service->withinAdvanceWindow($area, $lateSlot, '2026-09-17'))->toBeFalse();
});
