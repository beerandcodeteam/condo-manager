<?php

namespace Database\Factories;

use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommonAreaSlot>
 */
class CommonAreaSlotFactory extends Factory
{
    use InheritsCondominium;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startHour = fake()->numberBetween(8, 19);

        return [
            'condominium_id' => fn (array $attributes) => $this->condominiumFromParents($attributes, ['common_area_id' => CommonArea::class]),
            'common_area_id' => fn (array $attributes) => CommonArea::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'starts_at' => sprintf('%02d:00:00', $startHour),
            'ends_at' => sprintf('%02d:00:00', $startHour + 4),
        ];
    }
}
