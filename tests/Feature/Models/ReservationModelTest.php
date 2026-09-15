<?php

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('startsAtInCondoTimezone combines date and start time in the condominium timezone', function () {
    $reservation = Reservation::factory()->create(['date' => '2026-09-20', 'starts_at' => '12:00:00'])->fresh();

    $startsAt = $reservation->startsAtInCondoTimezone();

    expect($startsAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($startsAt->format('Y-m-d H:i:s'))->toBe('2026-09-20 12:00:00')
        ->and($startsAt->getTimezone()->getName())->toBe('America/Sao_Paulo')
        ->and($startsAt->utc()->format('Y-m-d H:i'))->toBe('2026-09-20 15:00');
});

test('a soft deleted slot is still reachable from the reservation', function () {
    $reservation = Reservation::factory()->create();

    $reservation->slot->delete();

    expect($reservation->fresh()->slot->id)->toBe($reservation->common_area_slot_id)
        ->and($reservation->fresh()->slot->trashed())->toBeTrue();
});

test('active scope excludes cancelled reservations', function () {
    $activeReservation = Reservation::factory()->create();
    Reservation::factory()->cancelled()->create();

    expect(Reservation::active()->pluck('id')->all())->toBe([$activeReservation->id]);
});

test('upcoming scope resolves today in the condominium timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-16 01:30:00', 'UTC'));
    $todayInCondo = Reservation::factory()->create(['date' => '2026-09-15']);
    Reservation::factory()->create(['date' => '2026-09-14']);
    $future = Reservation::factory()->create(['date' => '2026-09-20']);

    expect(Reservation::upcoming()->pluck('id')->all())->toEqualCanonicalizing([$todayInCondo->id, $future->id]);
});
