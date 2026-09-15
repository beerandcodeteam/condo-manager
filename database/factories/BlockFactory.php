<?php

namespace Database\Factories;

use App\Models\Block;
use App\Models\Condominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Block>
 */
class BlockFactory extends Factory
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
            'name' => fake()->unique()->regexify('[A-Z][0-9]{2}'),
        ];
    }
}
