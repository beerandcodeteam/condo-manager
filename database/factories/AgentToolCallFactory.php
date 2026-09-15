<?php

namespace Database\Factories;

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Resident;
use App\Models\ToolCallResult;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentToolCall>
 */
class AgentToolCallFactory extends Factory
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
            'agent_tool_id' => AgentTool::idFor(AgentTool::RULES_SEARCH),
            'personal_access_token_id' => null,
            'resident_id' => null,
            'phone' => fn (array $attributes) => $this->parentAttribute($attributes['resident_id'], Resident::class, 'phone'),
            'tool_call_result_id' => ToolCallResult::idFor(ToolCallResult::SUCESSO),
            'http_status' => 200,
            'error_code' => null,
            'entities' => null,
            'latency_ms' => fake()->numberBetween(80, 1500),
        ];
    }

    public function sucesso(): static
    {
        return $this->state(fn (array $attributes) => [
            'tool_call_result_id' => ToolCallResult::idFor(ToolCallResult::SUCESSO),
            'http_status' => 200,
            'error_code' => null,
        ]);
    }

    /**
     * Successful call that found nothing (e.g. no rule article above the similarity threshold).
     */
    public function vazio(): static
    {
        return $this->state(fn (array $attributes) => [
            'tool_call_result_id' => ToolCallResult::idFor(ToolCallResult::VAZIO),
            'http_status' => 200,
            'error_code' => null,
        ]);
    }

    /**
     * Call refused by a business rule.
     */
    public function recusa(): static
    {
        return $this->state(fn (array $attributes) => [
            'tool_call_result_id' => ToolCallResult::idFor(ToolCallResult::RECUSA),
            'http_status' => 422,
            'error_code' => 'slot_unavailable',
        ]);
    }
}
