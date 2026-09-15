<?php

namespace Database\Factories;

use App\Models\Escalation;
use App\Models\EscalationAssignment;
use App\Models\User;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EscalationAssignment>
 */
class EscalationAssignmentFactory extends Factory
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
                'escalation_id' => Escalation::class,
                'user_id' => User::class,
            ]),
            'escalation_id' => fn (array $attributes) => Escalation::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'user_id' => fn (array $attributes) => User::factory()->sindico()->state(['condominium_id' => $attributes['condominium_id']]),
            'previous_user_id' => null,
        ];
    }
}
