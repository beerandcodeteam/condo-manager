<?php

use App\Models\Condominium;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Services\RuleDocuments\RuleSearchService;
use App\Support\Tenancy\CurrentCondominium;
use Database\Seeders\LookupSeeder;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    $this->regimento = RuleDocument::factory()->publicado()->for($this->condominium)->create(['title' => 'Regimento Interno 2024']);

    app(CurrentCondominium::class)->set($this->condominium);
});

function searchArticle(RuleDocument $document, float $similarity, array $attributes = []): RuleArticle
{
    return RuleArticle::factory()->for($document)->create([
        'embedding' => embeddingWithSimilarity($similarity),
        'embedded_at' => now(),
        ...$attributes,
    ]);
}

test('generates the embedding of the question and returns article, document and score', function () {
    Embeddings::fake([[queryEmbedding()]]);

    $article = searchArticle($this->regimento, 0.87, ['reference' => 'Art. 23, §1º', 'title' => 'Fachada']);

    $search = app(RuleSearchService::class)->search('posso furar a sacada?', 5);

    expect($search['results'])->toHaveCount(1)
        ->and($search['results'][0]['article']->is($article))->toBeTrue()
        ->and($search['results'][0]['document']->is($this->regimento))->toBeTrue()
        ->and($search['results'][0]['score'])->toBe(0.87)
        ->and($search['latency_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->inputs === ['posso furar a sacada?'] && $prompt->dimensions === 1536);
});

test('excludes documents em_revisao, substituido and of another condominium', function () {
    Embeddings::fake([[queryEmbedding()]]);

    $published = searchArticle($this->regimento, 0.9);
    searchArticle(RuleDocument::factory()->emRevisao()->for($this->condominium)->create(), 0.99);
    searchArticle(RuleDocument::factory()->substituido()->for($this->condominium)->create(), 0.99);
    searchArticle(RuleDocument::factory()->falhaIndexacao()->for($this->condominium)->create(), 0.99);
    searchArticle(RuleDocument::factory()->publicado()->for(Condominium::factory())->create(), 0.99);

    $results = app(RuleSearchService::class)->search('barulho', 10)['results'];

    expect(collect($results)->map(fn (array $result) => $result['article']->id)->all())->toBe([$published->id]);
});

test('results below the 0.5 minimum similarity are discarded', function () {
    Embeddings::fake([[queryEmbedding()]]);

    $atThreshold = searchArticle($this->regimento, 0.51);
    searchArticle($this->regimento, 0.49);
    searchArticle($this->regimento, 0.1);

    $results = app(RuleSearchService::class)->search('barulho', 10)['results'];

    expect(config('condo.rag.min_similarity'))->toBe(0.5)
        ->and(collect($results)->map(fn (array $result) => $result['article']->id)->all())->toBe([$atThreshold->id])
        ->and($results[0]['score'])->toBe(0.51);
});

test('results are ordered by score descending across published documents', function () {
    Embeddings::fake([[queryEmbedding()]]);

    $convencao = RuleDocument::factory()->convencao()->publicado()->for($this->condominium)->create();
    $medium = searchArticle($this->regimento, 0.7);
    $best = searchArticle($convencao, 0.95);
    $low = searchArticle($this->regimento, 0.6);

    $results = app(RuleSearchService::class)->search('animais', 5)['results'];

    expect(collect($results)->map(fn (array $result) => $result['article']->id)->all())->toBe([$best->id, $medium->id, $low->id])
        ->and(collect($results)->pluck('score')->all())->toBe([0.95, 0.7, 0.6])
        ->and($results[0]['document']->is($convencao))->toBeTrue();
});

test('the limit is respected', function () {
    Embeddings::fake([[queryEmbedding()]]);

    foreach ([0.9, 0.8, 0.7, 0.6] as $similarity) {
        searchArticle($this->regimento, $similarity);
    }

    $results = app(RuleSearchService::class)->search('garagem', 2)['results'];

    expect(collect($results)->pluck('score')->all())->toBe([0.9, 0.8]);
});

test('without published documents it returns no results and does not generate embeddings', function () {
    Embeddings::fake();

    $this->regimento->delete();
    searchArticle(RuleDocument::factory()->emRevisao()->for($this->condominium)->create(), 0.99);

    $search = app(RuleSearchService::class)->search('barulho', 5);

    expect($search['results'])->toBe([]);

    Embeddings::assertNothingGenerated();
});
