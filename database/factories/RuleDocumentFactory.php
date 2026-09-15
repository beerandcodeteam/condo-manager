<?php

namespace Database\Factories;

use App\Models\DocumentStatus;
use App\Models\DocumentType;
use App\Models\RuleDocument;
use App\Models\User;
use Database\Factories\Concerns\InheritsCondominium;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RuleDocument>
 */
class RuleDocumentFactory extends Factory
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
            'condominium_id' => fn (array $attributes) => $this->condominiumFromParents($attributes, ['uploaded_by_user_id' => User::class]),
            'document_type_id' => DocumentType::idFor(DocumentType::REGIMENTO),
            'document_status_id' => DocumentStatus::idFor(DocumentStatus::PROCESSANDO),
            'title' => 'Regimento Interno',
            'file_path' => 'rule-documents/'.Str::uuid().'.pdf',
            'processing_error' => null,
            'uploaded_by_user_id' => fn (array $attributes) => User::factory()->sindico()->state(['condominium_id' => $attributes['condominium_id']]),
            'published_by_user_id' => null,
            'published_at' => null,
        ];
    }

    public function convencao(): static
    {
        return $this->state(fn (array $attributes) => [
            'document_type_id' => DocumentType::idFor(DocumentType::CONVENCAO),
            'title' => 'Convenção do Condomínio',
        ]);
    }

    public function processando(): static
    {
        return $this->withStatus(DocumentStatus::PROCESSANDO);
    }

    public function emRevisao(): static
    {
        return $this->withStatus(DocumentStatus::EM_REVISAO);
    }

    public function falhaExtracao(): static
    {
        return $this->withStatus(DocumentStatus::FALHA_EXTRACAO, [
            'processing_error' => 'Não foi possível extrair o texto do PDF.',
        ]);
    }

    public function indexando(): static
    {
        return $this->withStatus(DocumentStatus::INDEXANDO);
    }

    public function falhaIndexacao(): static
    {
        return $this->withStatus(DocumentStatus::FALHA_INDEXACAO, [
            'processing_error' => 'Falha ao gerar os embeddings dos artigos.',
        ]);
    }

    public function publicado(): static
    {
        return $this->withStatus(DocumentStatus::PUBLICADO, [
            'published_by_user_id' => fn (array $attributes) => $attributes['uploaded_by_user_id'],
            'published_at' => now(),
        ]);
    }

    public function substituido(): static
    {
        return $this->withStatus(DocumentStatus::SUBSTITUIDO, [
            'published_by_user_id' => fn (array $attributes) => $attributes['uploaded_by_user_id'],
            'published_at' => now()->subMonths(6),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function withStatus(string $statusSlug, array $attributes = []): static
    {
        return $this->state(fn () => [
            'document_status_id' => DocumentStatus::idFor($statusSlug),
            ...$attributes,
        ]);
    }
}
