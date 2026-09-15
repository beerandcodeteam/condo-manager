<?php

namespace Database\Factories;

use App\Models\CommonArea;
use App\Models\Condominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommonArea>
 */
class CommonAreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'condominium_id' => Condominium::factory(),
            'name' => 'Espaço '.fake()->unique()->word(),
            'description' => fake()->sentence(),
            'is_active' => true,
            'min_advance_hours' => 24,
            'max_advance_days' => 60,
            'cancellation_deadline_hours' => 24,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
