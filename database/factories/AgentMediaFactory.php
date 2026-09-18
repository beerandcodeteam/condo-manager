<?php

namespace Database\Factories;

use App\Models\AgentMedia;
use App\Models\AgentMediaKind;
use App\Models\Resident;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentMedia>
 */
class AgentMediaFactory extends Factory
{
    use InheritsCondominium;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'condominium_id' => fn (array $attributes) => $this->condominiumFromParents($attributes, ['resident_id' => Resident::class]),
            'agent_media_kind_id' => AgentMediaKind::idFor(AgentMediaKind::IMAGEM),
            'resident_id' => null,
            'ticket_id' => null,
            'phone' => fake()->numerify('+55419########'),
            'file_path' => 'whatsapp/1/5541999990000/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(1000, 500000),
            'caption' => null,
            'transcription' => null,
        ];
    }

    public function audio(): static
    {
        return $this->state([
            'agent_media_kind_id' => AgentMediaKind::idFor(AgentMediaKind::AUDIO),
            'mime_type' => 'audio/ogg',
            'file_path' => 'whatsapp/1/5541999990000/'.fake()->uuid().'.ogg',
        ]);
    }

    public function documento(): static
    {
        return $this->state([
            'agent_media_kind_id' => AgentMediaKind::idFor(AgentMediaKind::DOCUMENTO),
            'mime_type' => 'application/pdf',
            'file_path' => 'whatsapp/1/5541999990000/'.fake()->uuid().'.pdf',
        ]);
    }
}
