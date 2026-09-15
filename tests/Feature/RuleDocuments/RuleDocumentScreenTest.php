<?php

use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create();
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
});

test('lists the documents of the condominium with type, status label and article count', function () {
    $published = RuleDocument::factory()->publicado()->for($this->condominium)->create(['title' => 'Regimento Interno 2024', 'created_at' => now()->subDays(6)]);
    RuleArticle::factory()->count(2)->for($published)->withEmbedding()->create();

    $statusFactories = [
        'Convenção processando' => ['convencao', 'processando', 'Convenção · Processando · 0 artigos'],
        'Regimento em revisão' => ['regimento', 'emRevisao', 'Regimento · Em revisão · 0 artigos'],
        'Regimento com falha na extração' => ['regimento', 'falhaExtracao', 'Regimento · Falha na extração · 0 artigos'],
        'Regimento indexando' => ['regimento', 'indexando', 'Regimento · Indexando · 0 artigos'],
        'Convenção com falha na indexação' => ['convencao', 'falhaIndexacao', 'Convenção · Falha na indexação · 0 artigos'],
        'Regimento antigo' => ['regimento', 'substituido', 'Regimento · Substituído · 0 artigos'],
    ];

    foreach ($statusFactories as $title => [$type, $state, $meta]) {
        $factory = RuleDocument::factory()->{$state}()->for($this->condominium);

        ($type === 'convencao' ? $factory->convencao() : $factory)->create(['title' => $title]);
    }

    RuleDocument::factory()->publicado()->for(Condominium::factory())->create(['title' => 'Regimento de outro condomínio']);

    actingInPanel($this->sindico);

    $screen = $this->get(route('rule-documents.index'))
        ->assertOk()
        ->assertSee('Regimento Interno 2024')
        ->assertSee('Regimento · Publicado · 2 artigos')
        ->assertSee('+ Enviar PDF')
        ->assertDontSee('Regimento de outro condomínio');

    foreach ($statusFactories as $title => [, , $meta]) {
        $screen->assertSee($title)->assertSee($meta);
    }
});

test('a published document shows the ok pill with the indexed articles and a download link', function () {
    $document = RuleDocument::factory()->publicado()->for($this->condominium)->create(['title' => 'Regimento Interno 2024']);
    RuleArticle::factory()->for($document)->withEmbedding()->create(['reference' => 'Art. 14', 'title' => 'Obras e reformas', 'body' => 'Obras de segunda a sexta.', 'position' => 1]);
    RuleArticle::factory()->for($document)->withEmbedding()->create(['reference' => 'Art. 15', 'position' => 2]);
    RuleArticle::factory()->for($document)->create(['reference' => 'Art. 16', 'position' => 3]);

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->assertSeeHtml('grid-cols-[240px_1fr_340px]')
        ->assertSeeHtml('grid-cols-[64px_1fr_auto]')
        ->assertSee('Indexado · 2 trechos')
        ->assertSeeInOrder(['Art. 14', 'Obras e reformas', 'Obras de segunda a sexta.', 'Art. 15', 'Art. 16'])
        ->assertSeeHtml('href="'.route('rule-documents.download', $document).'"');
});

test('each status shows its pill and failures show the processing error with the retry action', function (string $state, string $pill, ?string $action) {
    $document = RuleDocument::factory()->{$state}()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    $screen = Livewire::test('pages::rule-documents', ['selectedDocumentId' => $document->id])
        ->assertSee($pill);

    if ($action !== null) {
        $screen->assertSee($document->processing_error)->assertSee($action);
    } else {
        $screen->assertDontSee('Reenviar PDF')->assertDontSee('Tentar novamente');
    }
})->with([
    'em revisão' => ['emRevisao', 'Em revisão', null],
    'processando' => ['processando', 'Processando', null],
    'indexando' => ['indexando', 'Indexando', null],
    'falha na extração' => ['falhaExtracao', 'Falha na extração', 'Reenviar PDF'],
    'falha na indexação' => ['falhaIndexacao', 'Falha na indexação', 'Tentar novamente'],
    'substituído' => ['substituido', 'Substituído', null],
]);

test('selecting a document shows its articles', function () {
    $first = RuleDocument::factory()->publicado()->for($this->condominium)->create(['title' => 'Regimento Interno', 'created_at' => now()]);
    $second = RuleDocument::factory()->convencao()->emRevisao()->for($this->condominium)->create(['title' => 'Convenção', 'created_at' => now()->subDay()]);
    RuleArticle::factory()->for($first)->create(['reference' => 'Art. 1º', 'body' => 'Artigo do regimento']);
    RuleArticle::factory()->for($second)->create(['reference' => 'Cl. 9ª', 'body' => 'Cláusula da convenção']);

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->assertSee('Artigo do regimento')
        ->assertDontSee('Cláusula da convenção')
        ->call('selectDocument', $second->id)
        ->assertSet('selectedDocumentId', $second->id)
        ->assertSee('Cláusula da convenção')
        ->assertDontSee('Artigo do regimento');
});

test('"citado N×" matches the tool calls that returned the article, for published and replaced documents', function (string $state) {
    $document = RuleDocument::factory()->{$state}()->for($this->condominium)->create();
    $cited = RuleArticle::factory()->for($document)->withEmbedding()->create(['position' => 1]);
    $notCited = RuleArticle::factory()->for($document)->withEmbedding()->create(['position' => 2]);

    AgentToolCall::factory()->count(3)->for($this->condominium)->create(['entities' => ['article_ids' => [$cited->id]]]);
    AgentToolCall::factory()->for($this->condominium)->create(['entities' => ['article_ids' => [$notCited->id + 1000, $cited->id]]]);
    AgentToolCall::factory()->vazio()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    expect(AgentToolCall::query()->forArticle($cited->id)->count())->toBe(4);

    Livewire::test('pages::rule-documents', ['selectedDocumentId' => $document->id])
        ->assertSeeInOrder([$cited->reference, 'citado 4×', $notCited->reference, 'citado 0×']);
})->with(['publicado', 'substituido']);

test('articles of documents in review do not show citations', function () {
    $document = RuleDocument::factory()->emRevisao()->for($this->condominium)->create();
    RuleArticle::factory()->for($document)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')->assertDontSee('citado');
});

test('the screen refreshes every 5 seconds only while the selected document is processando or indexando', function (string $state, bool $polls) {
    $document = RuleDocument::factory()->{$state}()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    $screen = Livewire::test('pages::rule-documents', ['selectedDocumentId' => $document->id]);

    $polls ? $screen->assertSeeHtml('wire:poll.5s') : $screen->assertDontSeeHtml('wire:poll');
})->with([
    'processando' => ['processando', true],
    'indexando' => ['indexando', true],
    'em revisão' => ['emRevisao', false],
    'falha na extração' => ['falhaExtracao', false],
    'falha na indexação' => ['falhaIndexacao', false],
    'publicado' => ['publicado', false],
    'substituído' => ['substituido', false],
]);

test('without documents the screen shows the empty state with the upload action', function () {
    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->assertSee('Nenhum documento enviado')
        ->assertSee('+ Enviar PDF')
        ->assertDontSeeHtml('wire:poll');
});

test('zelador receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();

    $this->actingAs($zelador)->get(route('rule-documents.index'))->assertForbidden();
});
