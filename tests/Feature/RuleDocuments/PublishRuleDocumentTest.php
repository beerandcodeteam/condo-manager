<?php

use App\Exceptions\RuleDocumentActionBlockedException;
use App\Jobs\IndexRuleArticles;
use App\Models\Condominium;
use App\Models\DocumentStatus;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Models\User;
use App\Services\RuleDocuments\RuleDocumentService;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create();
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
    $this->service = app(RuleDocumentService::class);
});

function publishedCount(Condominium $condominium, RuleDocument $document): int
{
    return RuleDocument::query()
        ->where('condominium_id', $condominium->id)
        ->where('document_type_id', $document->document_type_id)
        ->published()
        ->count();
}

test('publishing moves the document to indexando and queues the indexing job', function () {
    Queue::fake();

    $document = RuleDocument::factory()->emRevisao()->for($this->condominium)->create();
    RuleArticle::factory()->for($document)->create();

    $this->service->publish($document, $this->sindico);

    expect($document->refresh()->hasStatus(DocumentStatus::INDEXANDO))->toBeTrue()
        ->and($document->published_by_user_id)->toBe($this->sindico->id)
        ->and($document->published_at)->toBeNull();

    Queue::assertPushed(IndexRuleArticles::class, fn (IndexRuleArticles $job) => $job->document->is($document));
});

test('publishing from the screen stores 1536-dimension embeddings and publishes the document', function () {
    Embeddings::fake();

    $document = RuleDocument::factory()->emRevisao()->for($this->condominium)->create();
    $fachada = RuleArticle::factory()->for($document)->create(['reference' => 'Art. 23', 'title' => 'Fachada', 'body' => 'É vedado alterar a fachada.', 'position' => 1]);
    $silencio = RuleArticle::factory()->for($document)->create(['reference' => 'Art. 24', 'title' => null, 'body' => 'Silêncio das 22h às 8h.', 'position' => 2]);

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->call('publish')
        ->assertDispatched('toast', type: 'success');

    $document->refresh();

    expect($document->hasStatus(DocumentStatus::PUBLICADO))->toBeTrue()
        ->and($document->published_by_user_id)->toBe($this->sindico->id)
        ->and($document->published_at)->not->toBeNull()
        ->and($document->processing_error)->toBeNull();

    foreach ([$fachada, $silencio] as $article) {
        $article->refresh();

        expect($article->embedding)->toBeArray()->toHaveCount(1536)
            ->and($article->embedded_at)->not->toBeNull();
    }

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->dimensions === 1536
        && $prompt->inputs === ["Art. 23\nFachada\nÉ vedado alterar a fachada.", "Art. 24\nSilêncio das 22h às 8h."]);

    Livewire::test('pages::rule-documents')->assertSee('Indexado · 2 trechos');
});

test('embeddings are generated in batches of up to 100 articles', function () {
    $batchSizes = [];

    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$batchSizes) {
        $batchSizes[] = count($prompt->inputs);

        return array_map(fn () => Embeddings::fakeEmbedding($prompt->dimensions), $prompt->inputs);
    });

    $document = RuleDocument::factory()->emRevisao()->for($this->condominium)->create();
    RuleArticle::factory()->count(150)->for($document)->sequence(fn ($sequence) => ['position' => $sequence->index + 1])->create();

    $this->service->publish($document, $this->sindico);

    expect($batchSizes)->toBe([100, 50])
        ->and($document->refresh()->hasStatus(DocumentStatus::PUBLICADO))->toBeTrue()
        ->and(RuleArticle::query()->where('rule_document_id', $document->id)->whereNull('embedding')->count())->toBe(0);
});

test('the previously published document of the same type becomes substituido and the other type is unchanged', function () {
    Embeddings::fake();

    $previousRegimento = RuleDocument::factory()->publicado()->for($this->condominium)->create();
    $convencao = RuleDocument::factory()->convencao()->publicado()->for($this->condominium)->create();
    $otherCondominiumRegimento = RuleDocument::factory()->publicado()->for(Condominium::factory())->create();

    $newRegimento = RuleDocument::factory()->emRevisao()->for($this->condominium)->create();
    RuleArticle::factory()->for($newRegimento)->create();

    $this->service->publish($newRegimento, $this->sindico);

    expect($newRegimento->refresh()->hasStatus(DocumentStatus::PUBLICADO))->toBeTrue()
        ->and($previousRegimento->refresh()->hasStatus(DocumentStatus::SUBSTITUIDO))->toBeTrue()
        ->and($convencao->refresh()->hasStatus(DocumentStatus::PUBLICADO))->toBeTrue()
        ->and($otherCondominiumRegimento->refresh()->hasStatus(DocumentStatus::PUBLICADO))->toBeTrue();
});

test('a provider failure moves the document to falha_indexacao, keeps it out of the search and keeps the previous version', function () {
    Embeddings::fake(fn () => throw new RuntimeException('OpenAI indisponível'));

    $previousRegimento = RuleDocument::factory()->publicado()->for($this->condominium)->create();
    $document = RuleDocument::factory()->emRevisao()->for($this->condominium)->create();
    RuleArticle::factory()->count(2)->for($document)->create();

    $this->service->publish($document, $this->sindico);

    $document->refresh();

    expect($document->hasStatus(DocumentStatus::FALHA_INDEXACAO))->toBeTrue()
        ->and($document->processing_error)->toBe('OpenAI indisponível')
        ->and($document->published_at)->toBeNull()
        ->and(RuleDocument::query()->published()->pluck('id')->all())->toBe([$previousRegimento->id])
        ->and($previousRegimento->refresh()->hasStatus(DocumentStatus::PUBLICADO))->toBeTrue();

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents', ['selectedDocumentId' => $document->id])
        ->assertSee('Falha na indexação')
        ->assertSee('OpenAI indisponível')
        ->assertSee('Tentar novamente');
});

test('a document with falha_indexacao can be published again', function () {
    Embeddings::fake(fn () => throw new RuntimeException('OpenAI indisponível'));

    $document = RuleDocument::factory()->emRevisao()->for($this->condominium)->create();
    RuleArticle::factory()->count(2)->for($document)->create();

    $this->service->publish($document, $this->sindico);

    expect($document->refresh()->hasStatus(DocumentStatus::FALHA_INDEXACAO))->toBeTrue();

    Embeddings::fake();
    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents', ['selectedDocumentId' => $document->id])
        ->call('publish')
        ->assertDispatched('toast', type: 'success');

    $document->refresh();

    expect($document->hasStatus(DocumentStatus::PUBLICADO))->toBeTrue()
        ->and($document->processing_error)->toBeNull()
        ->and(RuleArticle::query()->where('rule_document_id', $document->id)->whereNotNull('embedding')->count())->toBe(2);
});

test('publishing without articles is blocked', function () {
    Queue::fake();

    $document = RuleDocument::factory()->emRevisao()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->call('publish')
        ->assertDispatched('toast', type: 'error', message: 'Adicione ao menos um artigo antes de publicar.');

    expect($document->refresh()->hasStatus(DocumentStatus::EM_REVISAO))->toBeTrue()
        ->and($document->published_by_user_id)->toBeNull();

    Queue::assertNothingPushed();
});

test('only documents in review or with falha_indexacao can be published', function (string $state) {
    Queue::fake();

    $document = RuleDocument::factory()->{$state}()->for($this->condominium)->create();
    RuleArticle::factory()->for($document)->create();

    expect(fn () => $this->service->publish($document, $this->sindico))
        ->toThrow(RuleDocumentActionBlockedException::class, 'Só documentos em revisão ou com falha na indexação podem ser publicados.');

    Queue::assertNothingPushed();
})->with(['processando', 'falhaExtracao', 'indexando', 'publicado', 'substituido']);

test('there are never two published documents of the same type in the condominium', function () {
    Embeddings::fake();

    $documents = collect(range(1, 3))->map(function (int $index) {
        $document = RuleDocument::factory()->emRevisao()->for($this->condominium)->create(['title' => "Regimento v{$index}"]);
        RuleArticle::factory()->for($document)->create();

        return $document;
    });

    foreach ($documents as $document) {
        $this->service->publish($document, $this->sindico);

        expect(publishedCount($this->condominium, $document))->toBe(1)
            ->and($document->refresh()->hasStatus(DocumentStatus::PUBLICADO))->toBeTrue();
    }

    expect($documents->map(fn (RuleDocument $document) => $document->refresh()->hasStatus(DocumentStatus::SUBSTITUIDO))->all())
        ->toBe([true, true, false]);
});

test('a document whose articles are not all indexed is not published', function () {
    $document = RuleDocument::factory()->indexando()->for($this->condominium)->create();
    RuleArticle::factory()->for($document)->withEmbedding()->create();
    RuleArticle::factory()->for($document)->create();

    expect($this->service->completePublication($document))->toBeFalse()
        ->and($document->refresh()->hasStatus(DocumentStatus::INDEXANDO))->toBeTrue();
});
