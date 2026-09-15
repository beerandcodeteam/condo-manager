<?php

namespace Database\Factories;

use App\Models\RuleArticle;
use App\Models\RuleDocument;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuleArticle>
 */
class RuleArticleFactory extends Factory
{
    use InheritsCondominium;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 999);

        return [
            'condominium_id' => fn (array $attributes) => $this->condominiumFromParents($attributes, ['rule_document_id' => RuleDocument::class]),
            'rule_document_id' => fn (array $attributes) => RuleDocument::factory()->state(['condominium_id' => $attributes['condominium_id']]),
            'reference' => "Art. {$number}",
            'title' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'position' => $number,
            'embedding' => null,
            'embedded_at' => null,
        ];
    }

    /**
     * Article already indexed with a text-embedding-3-small sized vector.
     */
    public function withEmbedding(): static
    {
        return $this->state(fn (array $attributes) => [
            'embedding' => array_map(
                fn (): float => fake()->randomFloat(6, -1, 1),
                range(1, config('condo.rag.embedding_dimensions')),
            ),
            'embedded_at' => now(),
        ]);
    }
}
