<?php

namespace Database\Factories;

use App\Models\Block;
use App\Models\Unit;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
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
            'condominium_id' => fn (array $attributes) => $this->condominiumFromParents($attributes, ['block_id' => Block::class]),
            'block_id' => null,
            'number' => (string) fake()->unique()->numberBetween(101, 99999),
        ];
    }

    /**
     * Unit inside a block of the same condominium.
     */
    public function withBlock(): static
    {
        return $this->state(fn (array $attributes) => [
            'block_id' => fn (array $attributes) => Block::factory()->state(['condominium_id' => $attributes['condominium_id']]),
        ]);
    }
}
