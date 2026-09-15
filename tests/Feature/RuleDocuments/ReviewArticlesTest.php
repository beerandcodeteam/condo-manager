<?php

use App\Exceptions\RuleDocumentActionBlockedException;
use App\Models\Condominium;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Models\User;
use App\Services\RuleDocuments\RuleDocumentService;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create();
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
    $this->document = RuleDocument::factory()->emRevisao()->for($this->condominium)->create();
});

/**
 * Create articles for the document with positions 1..n.
 *
 * @return list<RuleArticle>
 */
function reviewArticles(RuleDocument $document, int $count): array
{
    return collect(range(1, $count))
        ->map(fn (int $position): RuleArticle => RuleArticle::factory()->for($document)->create([
            'reference' => "Art. {$position}º",
            'position' => $position,
        ]))
        ->all();
}

/**
 * @return list<array{0: int, 1: int}>
 */
function articleOrder(RuleDocument $document): array
{
    return RuleArticle::query()
        ->where('rule_document_id', $document->id)
        ->orderBy('position')
        ->get()
        ->map(fn (RuleArticle $article): array => [$article->id, $article->position])
        ->all();
}

test('editing an article saves reference, title and text', function () {
    [$article] = reviewArticles($this->document, 1);

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->assertSee('+ Artigo')
        ->call('editArticle', $article->id)
        ->assertSet('editingArticleId', $article->id)
        ->assertSet('articleReference', $article->reference)
        ->set('articleReference', ' Art. 23, §1º ')
        ->set('articleTitle', 'Fachada')
        ->set('articleBody', 'É vedado alterar a fachada, incluindo perfurações em sacadas.')
        ->call('saveArticle')
        ->assertHasNoErrors()
        ->assertSet('editingArticleId', null)
        ->assertDispatched('toast', type: 'success', message: 'Artigo salvo.')
        ->assertSee('Art. 23, §1º');

    expect($article->refresh())
        ->reference->toBe('Art. 23, §1º')
        ->title->toBe('Fachada')
        ->body->toBe('É vedado alterar a fachada, incluindo perfurações em sacadas.')
        ->position->toBe(1);
});

test('an empty title is saved as null', function () {
    [$article] = reviewArticles($this->document, 1);

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->call('editArticle', $article->id)
        ->set('articleTitle', '   ')
        ->call('saveArticle')
        ->assertHasNoErrors();

    expect($article->refresh()->title)->toBeNull();
});

test('reference and text are required', function () {
    [$article] = reviewArticles($this->document, 1);
    $originalReference = $article->reference;

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->call('editArticle', $article->id)
        ->set('articleReference', '')
        ->set('articleBody', '')
        ->call('saveArticle')
        ->assertHasErrors(['articleReference' => 'required', 'articleBody' => 'required'])
        ->assertSet('editingArticleId', $article->id)
        ->set('articleReference', Str::repeat('a', 256))
        ->set('articleBody', 'Texto')
        ->call('saveArticle')
        ->assertHasErrors(['articleReference' => 'max']);

    expect($article->refresh()->reference)->toBe($originalReference);
});

test('adding an article appends it at the end and deleting keeps positions contiguous', function () {
    [$first, $second, $third] = reviewArticles($this->document, 3);

    actingInPanel($this->sindico);

    $screen = Livewire::test('pages::rule-documents')
        ->call('createArticle')
        ->assertSet('isAddingArticle', true)
        ->set('articleReference', 'Art. 4º')
        ->set('articleBody', 'Casos omissos são resolvidos pela assembleia.')
        ->call('saveArticle')
        ->assertHasNoErrors()
        ->assertSet('isAddingArticle', false)
        ->assertDispatched('toast', type: 'success', message: 'Artigo adicionado.');

    Livewire::test('pages::rule-documents')
        ->assertSeeInOrder(['Art. 3º', 'Art. 4º', 'Casos omissos são resolvidos pela assembleia.']);

    $added = RuleArticle::query()->where('reference', 'Art. 4º')->sole();

    expect($added->position)->toBe(4)
        ->and($added->title)->toBeNull()
        ->and($added->condominium_id)->toBe($this->condominium->id)
        ->and($added->rule_document_id)->toBe($this->document->id);

    $screen->call('deleteArticle', $second->id)
        ->assertDispatched('toast', type: 'success', message: 'Artigo excluído.');

    expect(RuleArticle::query()->find($second->id))->toBeNull()
        ->and(articleOrder($this->document))->toBe([[$first->id, 1], [$third->id, 2], [$added->id, 3]]);
});

test('moving articles up and down reorders positions contiguously', function () {
    [$first, $second, $third] = reviewArticles($this->document, 3);

    actingInPanel($this->sindico);

    $screen = Livewire::test('pages::rule-documents')
        ->call('moveArticle', $third->id, -1);

    expect(articleOrder($this->document))->toBe([[$first->id, 1], [$third->id, 2], [$second->id, 3]]);

    $screen->call('moveArticle', $first->id, 1);

    expect(articleOrder($this->document))->toBe([[$third->id, 1], [$first->id, 2], [$second->id, 3]]);

    $screen->call('moveArticle', $third->id, -1)
        ->call('moveArticle', $second->id, 1);

    expect(articleOrder($this->document))->toBe([[$third->id, 1], [$first->id, 2], [$second->id, 3]]);

    Livewire::test('pages::rule-documents')
        ->assertSeeInOrder([$third->reference, $first->reference, $second->reference]);
});

test('the publish button is disabled without articles', function () {
    actingInPanel($this->sindico);

    $publishButton = '/<button\b(?:(?!<button\b)[^>])*?data-publish[^>]*>/s';

    $html = Livewire::test('pages::rule-documents')->assertSee('Nenhum artigo.')->html();

    expect(preg_match($publishButton, $html, $withoutArticles))->toBe(1)
        ->and($withoutArticles[0])->toContain('disabled="disabled"');

    RuleArticle::factory()->for($this->document)->create();

    $html = Livewire::test('pages::rule-documents')->html();

    expect(preg_match($publishButton, $html, $withArticles))->toBe(1)
        ->and($withArticles[0])->not->toContain('disabled="disabled"');
});

test('published and replaced documents are read-only on the screen', function (string $state) {
    $document = RuleDocument::factory()->{$state}()->for($this->condominium)->create();
    RuleArticle::factory()->for($document)->withEmbedding()->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents', ['selectedDocumentId' => $document->id])
        ->assertDontSee('+ Artigo')
        ->assertDontSee('Publicar')
        ->assertDontSeeHtml('data-edit-article')
        ->assertDontSeeHtml('data-delete-article')
        ->assertDontSeeHtml('data-move-up');
})->with(['publicado', 'substituido']);

test('editing, adding, deleting or moving articles of a published document is refused', function () {
    $document = RuleDocument::factory()->publicado()->for($this->condominium)->create();
    $article = RuleArticle::factory()->for($document)->withEmbedding()->create(['reference' => 'Art. 1º', 'body' => 'Texto publicado', 'position' => 1]);
    $other = RuleArticle::factory()->for($document)->withEmbedding()->create(['reference' => 'Art. 2º', 'position' => 2]);

    actingInPanel($this->sindico);

    $blocked = 'Os artigos só podem ser alterados enquanto o documento está em revisão.';

    Livewire::test('pages::rule-documents', ['selectedDocumentId' => $document->id])
        ->call('editArticle', $article->id)
        ->set('articleReference', 'Art. 99')
        ->set('articleBody', 'Texto alterado')
        ->call('saveArticle')
        ->assertDispatched('toast', type: 'error', message: $blocked)
        ->call('deleteArticle', $other->id)
        ->assertDispatched('toast', type: 'error', message: $blocked)
        ->call('moveArticle', $other->id, -1)
        ->assertDispatched('toast', type: 'error', message: $blocked)
        ->call('createArticle')
        ->set('articleReference', 'Art. 3º')
        ->set('articleBody', 'Novo')
        ->call('saveArticle')
        ->assertDispatched('toast', type: 'error', message: $blocked);

    expect($article->refresh())->reference->toBe('Art. 1º')->body->toBe('Texto publicado')
        ->and(articleOrder($document))->toBe([[$article->id, 1], [$other->id, 2]]);

    expect(fn () => app(RuleDocumentService::class)->updateArticle($article, ['reference' => 'X', 'title' => null, 'body' => 'Y']))
        ->toThrow(RuleDocumentActionBlockedException::class);
});

test('articles of another document or condominium cannot be edited', function () {
    reviewArticles($this->document, 1);
    $foreignArticle = RuleArticle::factory()->for(RuleDocument::factory()->emRevisao()->for(Condominium::factory()))->create();

    actingInPanel($this->sindico);

    expect(fn () => Livewire::test('pages::rule-documents')->call('editArticle', $foreignArticle->id))
        ->toThrow(ModelNotFoundException::class);
});
