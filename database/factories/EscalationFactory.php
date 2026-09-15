<?php

namespace Database\Factories;

use App\Models\Escalation;
use App\Models\EscalationReason;
use App\Models\EscalationStatus;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Escalation>
 */
class EscalationFactory extends Factory
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
                'ticket_id' => Ticket::class,
                'assigned_user_id' => User::class,
            ]),
            'unit_id' => fn (array $attributes) => $this->parentAttribute($attributes['resident_id'], Resident::class, 'unit_id')
                ?? Unit::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'resident_id' => fn (array $attributes) => Resident::factory()->state([
                'condominium_id' => $attributes['condominium_id'],
                'unit_id' => $attributes['unit_id'],
            ]),
            'ticket_id' => null,
            'escalation_status_id' => EscalationStatus::idFor(EscalationStatus::PENDENTE),
            'escalation_reason_id' => EscalationReason::idFor(EscalationReason::PEDIU_HUMANO),
            'summary' => fake()->sentence(),
            'assigned_user_id' => null,
            'assigned_at' => null,
            'response' => null,
            'responded_by_user_id' => null,
            'resolved_at' => null,
        ];
    }

    public function pendente(): static
    {
        return $this->state(fn (array $attributes) => [
            'escalation_status_id' => EscalationStatus::idFor(EscalationStatus::PENDENTE),
        ]);
    }

    /**
     * Escalation taken by a síndico of the same condominium.
     */
    public function emAtendimento(): static
    {
        return $this->state(fn (array $attributes) => [
            'escalation_status_id' => EscalationStatus::idFor(EscalationStatus::EM_ATENDIMENTO),
            'assigned_user_id' => fn (array $attributes) => User::factory()->sindico()->state(['condominium_id' => $attributes['condominium_id']]),
            'assigned_at' => now(),
        ]);
    }

    /**
     * Escalation answered and closed by the assigned síndico.
     */
    public function resolvido(): static
    {
        return $this->emAtendimento()->state(fn (array $attributes) => [
            'escalation_status_id' => EscalationStatus::idFor(EscalationStatus::RESOLVIDO),
            'response' => fake()->sentence(),
            'responded_by_user_id' => fn (array $attributes) => $attributes['assigned_user_id'],
            'resolved_at' => now(),
        ]);
    }
}
