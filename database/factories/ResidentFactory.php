<?php

namespace Database\Factories;

use App\Models\Resident;
use App\Models\ResidentProfile;
use App\Models\Unit;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Resident>
 */
class ResidentFactory extends Factory
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
            'condominium_id' => fn (array $attributes) => $this->condominiumFromParents($attributes, ['unit_id' => Unit::class]),
            'unit_id' => fn (array $attributes) => Unit::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'resident_profile_id' => ResidentProfile::idFor(ResidentProfile::PROPRIETARIO),
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('+55419########'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'resident_profile_id' => ResidentProfile::idFor(ResidentProfile::PROPRIETARIO),
        ]);
    }

    public function tenant(): static
    {
        return $this->state(fn (array $attributes) => [
            'resident_profile_id' => ResidentProfile::idFor(ResidentProfile::INQUILINO),
        ]);
    }
}
