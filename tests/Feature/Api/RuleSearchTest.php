<?php

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Models\ToolCallResult;
use Database\Seeders\LookupSeeder;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;
    $this->regimento = RuleDocument::factory()->publicado()->for($this->condominium)->create(['title' => 'Regimento Interno 2024']);
});

function apiSearchArticle(RuleDocument $document, float $similarity, array $attributes = []): RuleArticle
{
    return RuleArticle::factory()->for($document)->create([
        'embedding' => embeddingWithSimilarity($similarity),
        'embedded_at' => now(),
        ...$attributes,
    ]);
}

test('responds with document, article, full text and score ordered by score', function () {
    Embeddings::fake([[queryEmbedding()]]);

    $fachada = apiSearchArticle($this->regimento, 0.87, [
        'reference' => 'Art. 23, §1º',
        'title' => 'Fachada',
        'body' => 'É vedado alterar a fachada, incluindo perfurações em sacadas.',
    ]);
    $convencao = RuleDocument::factory()->convencao()->publicado()->for($this->condominium)->create(['title' => 'Convenção 2019']);
    $obras = apiSearchArticle($convencao, 0.62, ['reference' => 'Cl. 9ª', 'title' => null, 'body' => 'Obras dependem de aprovação.']);

    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), ['query' => 'posso furar a sacada?', 'limit' => 5])
        ->assertOk()
        ->assertExactJson([
            'results' => [
                [
                    'document' => ['id' => $this->regimento->id, 'type' => 'regimento', 'title' => 'Regimento Interno 2024'],
                    'article' => ['id' => $fachada->id, 'reference' => 'Art. 23, §1º', 'title' => 'Fachada'],
                    'text' => 'É vedado alterar a fachada, incluindo perfurações em sacadas.',
                    'score' => 0.87,
                ],
                [
                    'document' => ['id' => $convencao->id, 'type' => 'convencao', 'title' => 'Convenção 2019'],
                    'article' => ['id' => $obras->id, 'reference' => 'Cl. 9ª', 'title' => null],
                    'text' => 'Obras dependem de aprovação.',
                    'score' => 0.62,
                ],
            ],
        ]);

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->inputs === ['posso furar a sacada?']);
});

test('limit defaults to 5 and is respected', function () {
    Embeddings::fake([[queryEmbedding()], [queryEmbedding()]]);

    foreach ([0.95, 0.9, 0.85, 0.8, 0.75, 0.7, 0.65] as $similarity) {
        apiSearchArticle($this->regimento, $similarity);
    }

    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), ['query' => 'barulho'])
        ->assertOk()
        ->assertJsonCount(5, 'results');

    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), ['query' => 'barulho', 'limit' => '2'])
        ->assertOk()
        ->assertJsonPath('results.*.score', [0.95, 0.9]);
});

test('limit must be an integer between 1 and 10', function (mixed $limit) {
    Embeddings::fake();

    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), ['query' => 'barulho', 'limit' => $limit])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonValidationErrors('limit');

    Embeddings::assertNothingGenerated();
})->with([11, 0, 'cinco', 'boolean true' => true]);

test('query is required and cannot be empty', function (array $payload) {
    Embeddings::fake();

    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonValidationErrors('query');

    Embeddings::assertNothingGenerated();
})->with([
    'missing' => [[]],
    'empty' => [['query' => '']],
    'blank' => [['query' => '   ']],
    'not a string' => [['query' => ['barulho']]],
]);

test('a query longer than the maximum length responds 422 before calling the embeddings provider', function () {
    Embeddings::fake();

    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), ['query' => str_repeat('a', config('condo.rag.max_query_length') + 1)])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonValidationErrors('query');

    Embeddings::assertNothingGenerated();

    expect(AgentToolCall::query()->withoutGlobalScopes()->sole()->error_code)->toBe('validation_error');
});

test('a query at exactly the maximum length is searched', function () {
    Embeddings::fake([[queryEmbedding()]]);

    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), ['query' => str_repeat('a', config('condo.rag.max_query_length'))])
        ->assertOk();

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => mb_strlen($prompt->inputs[0]) === config('condo.rag.max_query_length'));
});

test('limit 11 logs the call as recusa with validation_error', function () {
    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), ['query' => 'barulho', 'limit' => 11])
        ->assertUnprocessable();

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->agent_tool_id)->toBe(AgentTool::idFor(AgentTool::RULES_SEARCH))
        ->and($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::RECUSA))
        ->and($toolCall->error_code)->toBe('validation_error');
});

test('without results above the threshold responds results [] and logs vazio', function () {
    Embeddings::fake([[queryEmbedding()]]);

    apiSearchArticle($this->regimento, 0.3);

    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), ['query' => 'posso ter cachorro grande?'])
        ->assertOk()
        ->assertExactJson(['results' => []]);

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->agent_tool_id)->toBe(AgentTool::idFor(AgentTool::RULES_SEARCH))
        ->and($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::VAZIO))
        ->and($toolCall->http_status)->toBe(200)
        ->and($toolCall->entities)->toBeNull();
});

test('the tool call log stores entities.article_ids in the returned order and logs sucesso', function () {
    Embeddings::fake([[queryEmbedding()]]);

    $second = apiSearchArticle($this->regimento, 0.7);
    $first = apiSearchArticle($this->regimento, 0.9);
    $third = apiSearchArticle($this->regimento, 0.55);

    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), ['query' => 'barulho'])
        ->assertOk();

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->condominium_id)->toBe($this->condominium->id)
        ->and($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO))
        ->and($toolCall->entities)->toBe(['article_ids' => [$first->id, $second->id, $third->id]]);
});

test('documents of another condominium are never returned', function () {
    Embeddings::fake([[queryEmbedding()], [queryEmbedding()]]);

    $otherCondominium = Condominium::factory()->create();
    $foreign = apiSearchArticle(RuleDocument::factory()->publicado()->for($otherCondominium)->create(), 0.99);
    $own = apiSearchArticle($this->regimento, 0.6);

    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), ['query' => 'barulho', 'limit' => 10])
        ->assertOk()
        ->assertJsonPath('results.*.article.id', [$own->id]);

    $own->delete();

    $this->withToken($this->token)
        ->postJson(route('api.v1.rules_search'), ['query' => 'barulho', 'limit' => 10])
        ->assertOk()
        ->assertExactJson(['results' => []]);

    expect($foreign->fresh())->not->toBeNull();
});

test('requires the condominium token', function () {
    $this->postJson(route('api.v1.rules_search'), ['query' => 'barulho'])
        ->assertUnauthorized();
});
