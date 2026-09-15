<?php

use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create();
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
});

function testQuestionArticle(RuleDocument $document, float $similarity, array $attributes = []): RuleArticle
{
    return RuleArticle::factory()->for($document)->create([
        'embedding' => embeddingWithSimilarity($similarity),
        'embedded_at' => now(),
        ...$attributes,
    ]);
}

test('the card shows title, subtitle, field and clickable suggestions', function () {
    RuleDocument::factory()->publicado()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->assertSee('Testar pergunta')
        ->assertSee('Simula o que o agente encontraria no WhatsApp.')
        ->assertSee('Testar')
        ->assertSee('Posso fechar a sacada?')
        ->assertSee('Até que horas pode barulho?')
        ->assertSee('Posso ter cachorro grande?')
        ->assertDontSee('Publique o regimento ou a convenção para testar.');
});

test('shows the question and the best result with document, reference, excerpt, score and latency', function () {
    Embeddings::fake([[queryEmbedding()]]);

    $regimento = RuleDocument::factory()->publicado()->for($this->condominium)->create(['title' => 'Regimento Interno 2024']);
    testQuestionArticle($regimento, 0.93, [
        'reference' => 'Art. 23, §1º',
        'body' => 'É vedado alterar a fachada, incluindo o fechamento de sacadas com vidro. '.Str::repeat('Texto complementar do artigo. ', 10),
    ]);
    testQuestionArticle($regimento, 0.71, ['reference' => 'Art. 40', 'body' => 'Outro artigo menos relevante.']);

    actingInPanel($this->sindico);

    $screen = Livewire::test('pages::rule-documents')
        ->set('testQuestion', 'Posso fechar a sacada?')
        ->call('askQuestion')
        ->assertHasNoErrors();

    $testResult = $screen->get('testResult');

    expect($testResult['question'])->toBe('Posso fechar a sacada?')
        ->and($testResult['article']['citation'])->toBe('Regimento Interno 2024 · Art. 23, §1º')
        ->and($testResult['article']['score'])->toBe(0.93)
        ->and(mb_strlen($testResult['article']['excerpt']))->toBeLessThanOrEqual(200)->toBeGreaterThan(190)
        ->and($testResult['article']['excerpt'])->toStartWith('É vedado alterar a fachada')->toEndWith('…')
        ->and($testResult['latency_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);

    Livewire::test('pages::rule-documents')
        ->set('testResult', $testResult)
        ->assertSeeHtml('bg-[#dcf8c6]')
        ->assertSee('Posso fechar a sacada?')
        ->assertSee('Regimento Interno 2024 · Art. 23, §1º')
        ->assertSee('É vedado alterar a fachada')
        ->assertSee("consultar_regimento · {$testResult['latency_ms']} ms · score 0.93");

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->inputs === ['Posso fechar a sacada?']);
});

test('clicking a suggestion runs the search with that question', function () {
    Embeddings::fake([[queryEmbedding()]]);

    $regimento = RuleDocument::factory()->publicado()->for($this->condominium)->create();
    testQuestionArticle($regimento, 0.8, ['reference' => 'Art. 12', 'body' => 'Silêncio das 22h às 8h.']);

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->call('askSampleQuestion', 'Até que horas pode barulho?')
        ->assertSet('testQuestion', 'Até que horas pode barulho?')
        ->assertSet('testResult.article.score', 0.8)
        ->assertSet('testResult.article.excerpt', 'Silêncio das 22h às 8h.');

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->inputs === ['Até que horas pode barulho?']);
});

test('without results above the threshold shows "Nenhum artigo encontrado"', function () {
    Embeddings::fake([[queryEmbedding()]]);

    $regimento = RuleDocument::factory()->publicado()->for($this->condominium)->create();
    testQuestionArticle($regimento, 0.2);

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->set('testQuestion', 'Posso ter cachorro grande?')
        ->call('askQuestion')
        ->assertSet('testResult.article', null)
        ->assertSee('Nenhum artigo encontrado');
});

test('the question is required', function () {
    Embeddings::fake();
    RuleDocument::factory()->publicado()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->set('testQuestion', '')
        ->call('askQuestion')
        ->assertHasErrors(['testQuestion' => 'required'])
        ->assertSet('testResult', null);

    Embeddings::assertNothingGenerated();
});

test('without a published document it shows the warning, disables the button and does not call embeddings', function () {
    Embeddings::fake();

    $inReview = RuleDocument::factory()->emRevisao()->for($this->condominium)->create();
    testQuestionArticle($inReview, 0.99);
    RuleDocument::factory()->substituido()->for($this->condominium)->create();
    RuleDocument::factory()->publicado()->for(Condominium::factory())->create();

    actingInPanel($this->sindico);

    $screen = Livewire::test('pages::rule-documents')
        ->assertSee('Publique o regimento ou a convenção para testar.');

    expect(preg_match('/<button\b(?:(?!<button\b)[^>])*?data-test-question-submit[^>]*>/s', $screen->html(), $submitButton))->toBe(1)
        ->and($submitButton[0])->toContain('disabled="disabled"');

    $screen->set('testQuestion', 'Posso fechar a sacada?')
        ->call('askQuestion')
        ->call('askSampleQuestion', 'Posso ter cachorro grande?')
        ->assertSet('testResult', null)
        ->assertDontSeeHtml('data-test-result');

    Embeddings::assertNothingGenerated();
});

test('testing a question never writes to agent_tool_calls', function () {
    Embeddings::fake([[queryEmbedding()], [queryEmbedding()]]);

    $regimento = RuleDocument::factory()->publicado()->for($this->condominium)->create();
    testQuestionArticle($regimento, 0.9);

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->set('testQuestion', 'Posso fechar a sacada?')
        ->call('askQuestion')
        ->call('askSampleQuestion', 'Posso ter cachorro grande?');

    expect(AgentToolCall::query()->withoutGlobalScopes()->count())->toBe(0);
});
