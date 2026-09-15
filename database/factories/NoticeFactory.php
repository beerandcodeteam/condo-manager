<?php

namespace Database\Factories;

use App\Models\Notice;
use App\Models\User;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notice>
 */
class NoticeFactory extends Factory
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
            'condominium_id' => fn (array $attributes) => $this->condominiumFromParents($attributes, ['created_by_user_id' => User::class]),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'is_active' => true,
            'created_by_user_id' => fn (array $attributes) => User::factory()->sindico()->state(['condominium_id' => $attributes['condominium_id']]),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
