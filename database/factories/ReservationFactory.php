<?php

namespace Database\Factories;

use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Reservation;
use App\Models\ReservationCancellationOrigin;
use App\Models\ReservationOrigin;
use App\Models\ReservationStatus;
use App\Models\Resident;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    use InheritsCondominium;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'condominium_id' => fn (array $attributes) => $this->condominiumFromParents($attributes, [
                'resident_id' => Resident::class,
                'unit_id' => Unit::class,
                'common_area_slot_id' => CommonAreaSlot::class,
                'common_area_id' => CommonArea::class,
            ]),
            'common_area_id' => fn (array $attributes) => $this->parentAttribute($attributes['common_area_slot_id'], CommonAreaSlot::class, 'common_area_id')
                ?? CommonArea::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'common_area_slot_id' => fn (array $attributes) => CommonAreaSlot::factory()->state([
                'condominium_id' => $attributes['condominium_id'],
                'common_area_id' => $attributes['common_area_id'],
            ]),
            'unit_id' => fn (array $attributes) => $this->parentAttribute($attributes['resident_id'], Resident::class, 'unit_id')
                ?? Unit::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'resident_id' => fn (array $attributes) => Resident::factory()->state([
                'condominium_id' => $attributes['condominium_id'],
                'unit_id' => $attributes['unit_id'],
            ]),
            'reservation_status_id' => ReservationStatus::idFor(ReservationStatus::CONFIRMADA),
            'reservation_origin_id' => ReservationOrigin::idFor(ReservationOrigin::WHATSAPP),
            'created_by_user_id' => null,
            'date' => fake()->dateTimeBetween('+2 days', '+60 days')->format('Y-m-d'),
            'starts_at' => fn (array $attributes) => $this->parentAttribute($attributes['common_area_slot_id'], CommonAreaSlot::class, 'starts_at'),
            'ends_at' => fn (array $attributes) => $this->parentAttribute($attributes['common_area_slot_id'], CommonAreaSlot::class, 'ends_at'),
            'cancelled_at' => null,
            'reservation_cancellation_origin_id' => null,
            'cancellation_reason' => null,
            'cancelled_by_user_id' => null,
        ];
    }

    /**
     * Reservation cancelled by the resident.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'reservation_status_id' => ReservationStatus::idFor(ReservationStatus::CANCELADA),
            'cancelled_at' => now(),
            'reservation_cancellation_origin_id' => ReservationCancellationOrigin::idFor(ReservationCancellationOrigin::MORADOR),
        ]);
    }

    /**
     * Reservation created manually by a panel user.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'reservation_origin_id' => ReservationOrigin::idFor(ReservationOrigin::PAINEL),
            'created_by_user_id' => fn (array $attributes) => User::factory()->sindico()->state(['condominium_id' => $attributes['condominium_id']]),
        ]);
    }
}
