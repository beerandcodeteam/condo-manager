<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\TicketStatusChange;
use App\Models\User;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketStatusChange>
 */
class TicketStatusChangeFactory extends Factory
{
    use InheritsCondominium;

    /**
     * Define the model's default state: the initial entry of a ticket opened via API.
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
            'from_ticket_status_id' => null,
            'to_ticket_status_id' => TicketStatus::idFor(TicketStatus::ABERTO),
            'comment' => null,
            'user_id' => null,
        ];
    }
}
