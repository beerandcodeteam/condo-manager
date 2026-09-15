<?php

namespace Database\Factories;

use App\Models\Condominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Condominium>
 */
class CondominiumFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Residencial '.fake()->unique()->company(),
            'city' => fake()->city(),
            'whatsapp_number' => fake()->numerify('+55413#######'),
            'caretaker_name' => fake()->name(),
            'caretaker_phone' => fake()->numerify('+55419########'),
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '08:00:00',
            'webhook_url' => null,
            'webhook_secret' => null,
            'last_ticket_protocol' => 0,
        ];
    }

    /**
     * Indicate that the condominium has an outgoing webhook configured.
     */
    public function withWebhook(): static
    {
        return $this->state(fn (array $attributes) => [
            'webhook_url' => fake()->url(),
            'webhook_secret' => fake()->sha256(),
        ]);
    }
}
