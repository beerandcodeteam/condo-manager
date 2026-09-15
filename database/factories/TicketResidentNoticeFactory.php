<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketResidentNotice;
use App\Models\User;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketResidentNotice>
 */
class TicketResidentNoticeFactory extends Factory
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
                'ticket_id' => Ticket::class,
                'user_id' => User::class,
            ]),
            'ticket_id' => fn (array $attributes) => Ticket::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'user_id' => fn (array $attributes) => User::factory()->sindico()->state(['condominium_id' => $attributes['condominium_id']]),
            'message' => fake()->sentence(),
            'webhook_delivery_id' => null,
        ];
    }
}
