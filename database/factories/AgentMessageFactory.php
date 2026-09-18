<?php

namespace Database\Factories;

use App\Models\AgentMessage;
use App\Models\AgentMessageRole;
use App\Models\Resident;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentMessage>
 */
class AgentMessageFactory extends Factory
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
            'condominium_id' => fn (array $attributes) => $this->condominiumFromParents($attributes, ['resident_id' => Resident::class]),
            'agent_message_role_id' => AgentMessageRole::idFor(AgentMessageRole::MORADOR),
            'resident_id' => null,
            'phone' => fake()->numerify('+55419########'),
            'content' => fake()->sentence(),
        ];
    }

    public function fromAgent(): static
    {
        return $this->state(['agent_message_role_id' => AgentMessageRole::idFor(AgentMessageRole::AGENTE)]);
    }
}
