<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketPhoto;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketPhoto>
 */
class TicketPhotoFactory extends Factory
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
            'condominium_id' => fn (array $attributes) => $this->condominiumFromParents($attributes, ['ticket_id' => Ticket::class]),
            'ticket_id' => fn (array $attributes) => Ticket::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'file_path' => 'ticket-photos/'.Str::uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(50_000, 5_000_000),
        ];
    }
}
