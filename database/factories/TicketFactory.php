<?php

namespace Database\Factories;

use App\Models\Condominium;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketOrigin;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
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
                'resident_id' => Resident::class,
                'unit_id' => Unit::class,
                'ticket_category_id' => TicketCategory::class,
                'opened_by_user_id' => User::class,
            ]),
            'protocol_number' => fn (array $attributes) => $this->nextProtocolNumber($attributes['condominium_id']),
            'ticket_status_id' => TicketStatus::idFor(TicketStatus::ABERTO),
            'ticket_priority_id' => TicketPriority::idFor(TicketPriority::MEDIA),
            'ticket_origin_id' => TicketOrigin::idFor(TicketOrigin::WHATSAPP),
            'ticket_category_id' => fn (array $attributes) => TicketCategory::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'unit_id' => fn (array $attributes) => $this->parentAttribute($attributes['resident_id'], Resident::class, 'unit_id')
                ?? Unit::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'resident_id' => fn (array $attributes) => Resident::factory()->state([
                'condominium_id' => $attributes['condominium_id'],
                'unit_id' => $attributes['unit_id'],
            ]),
            'opened_by_user_id' => null,
            'description' => fake()->paragraph(),
            'location' => null,
        ];
    }

    public function aberto(): static
    {
        return $this->withStatus(TicketStatus::ABERTO);
    }

    public function emAndamento(): static
    {
        return $this->withStatus(TicketStatus::EM_ANDAMENTO);
    }

    public function resolvido(): static
    {
        return $this->withStatus(TicketStatus::RESOLVIDO);
    }

    public function cancelado(): static
    {
        return $this->withStatus(TicketStatus::CANCELADO);
    }

    /**
     * Ticket opened by a panel user, without a resident or unit.
     */
    public function fromPanel(): static
    {
        return $this->state(fn (array $attributes) => [
            'ticket_origin_id' => TicketOrigin::idFor(TicketOrigin::PAINEL),
            'unit_id' => null,
            'resident_id' => null,
            'opened_by_user_id' => fn (array $attributes) => User::factory()->sindico()->state(['condominium_id' => $attributes['condominium_id']]),
            'location' => 'Área comum',
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'ticket_priority_id' => TicketPriority::idFor(TicketPriority::ALTA),
        ]);
    }

    private function withStatus(string $statusSlug): static
    {
        return $this->state(fn (array $attributes) => [
            'ticket_status_id' => TicketStatus::idFor($statusSlug),
        ]);
    }

    /**
     * Advance the condominium protocol counter, as the application does when opening a ticket.
     */
    private function nextProtocolNumber(int $condominiumId): int
    {
        Condominium::whereKey($condominiumId)->increment('last_ticket_protocol');

        return (int) Condominium::whereKey($condominiumId)->value('last_ticket_protocol');
    }
}
