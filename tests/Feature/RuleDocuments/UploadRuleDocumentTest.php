<?php

use App\Jobs\ExtractRuleArticles;
use App\Models\Condominium;
use App\Models\DocumentStatus;
use App\Models\DocumentType;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Models\User;
use App\Services\RuleDocuments\RuleDocumentService;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
    Storage::fake(RuleDocumentService::DISK);

    $this->condominium = Condominium::factory()->create();
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
});

function fixturePdf(string $name, string $clientName = 'regimento.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($clientName, (string) file_get_contents(base_path("tests/Fixtures/{$name}")));
}

/**
 * Document stored on the fake private disk with the given fixture content, as the upload leaves it.
 */
function storedRuleDocument(RuleDocument $document, string $content): RuleDocument
{
    Storage::disk(RuleDocumentService::DISK)->put($document->file_path, $content);

    return $document;
}

test('a file that is not a PDF is rejected', function () {
    Queue::fake();
    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->call('openUploadForm')
        ->set('uploadType', DocumentType::REGIMENTO)
        ->set('uploadTitle', 'Regimento Interno 2024')
        ->set('uploadFile', UploadedFile::fake()->create('regimento.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
        ->call('upload')
        ->assertHasErrors(['uploadFile' => 'mimes']);

    expect(RuleDocument::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('type, title and file are required and the type must be regimento or convencao', function () {
    Queue::fake();
    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->call('openUploadForm')
        ->set('uploadType', 'ata')
        ->set('uploadTitle', '')
        ->call('upload')
        ->assertHasErrors(['uploadType' => 'in', 'uploadTitle' => 'required', 'uploadFile' => 'required']);

    expect(RuleDocument::query()->count())->toBe(0);
});

test('uploading stores the PDF, creates the document as processando and queues the extraction', function () {
    Queue::fake();
    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->call('openUploadForm')
        ->assertSet('showUploadForm', true)
        ->set('uploadType', DocumentType::CONVENCAO)
        ->set('uploadTitle', 'Convenção 2019')
        ->set('uploadFile', fixturePdf('regimento.pdf', 'convencao.pdf'))
        ->call('upload')
        ->assertHasNoErrors()
        ->assertSet('showUploadForm', false)
        ->assertDispatched('toast', type: 'success')
        ->assertSee('Convenção 2019')
        ->assertSee('Convenção · Processando · 0 artigos');

    $document = RuleDocument::query()->sole();

    expect($document->condominium_id)->toBe($this->condominium->id)
        ->and($document->document_type_id)->toBe(DocumentType::idFor(DocumentType::CONVENCAO))
        ->and($document->hasStatus(DocumentStatus::PROCESSANDO))->toBeTrue()
        ->and($document->title)->toBe('Convenção 2019')
        ->and($document->uploaded_by_user_id)->toBe($this->sindico->id)
        ->and($document->file_path)->toStartWith("rule-documents/{$this->condominium->id}/")
        ->and($document->file_path)->toEndWith('.pdf');

    Storage::disk(RuleDocumentService::DISK)->assertExists($document->file_path);
    Queue::assertPushed(ExtractRuleArticles::class, fn (ExtractRuleArticles $job) => $job->document->is($document));
});

test('the extraction job splits regimento.pdf into two articles in order and moves the document to em_revisao', function () {
    $document = storedRuleDocument(
        RuleDocument::factory()->processando()->for($this->condominium)->create(),
        (string) file_get_contents(base_path('tests/Fixtures/regimento.pdf')),
    );

    ExtractRuleArticles::dispatchSync($document);

    $document->refresh();
    $articles = RuleArticle::query()->where('rule_document_id', $document->id)->orderBy('position')->get();

    expect($document->hasStatus(DocumentStatus::EM_REVISAO))->toBeTrue()
        ->and($document->processing_error)->toBeNull()
        ->and($articles)->toHaveCount(2)
        ->and($articles->pluck('reference')->all())->toBe(['Art. 1º', 'Art. 2º'])
        ->and($articles->pluck('title')->all())->toBe(['Disposições gerais', 'Silêncio'])
        ->and($articles->pluck('position')->all())->toBe([1, 2])
        ->and($articles[0]->body)->toBe('Este regimento vale para todos os moradores e visitantes.')
        ->and($articles[1]->body)->toBe("O silêncio deve ser respeitado das 22h às 8h.\n§ 1º Obras ruidosas só em dias úteis.")
        ->and($articles->pluck('condominium_id')->unique()->all())->toBe([$this->condominium->id]);
});

test('a PDF without extractable text moves the document to falha_extracao with a message', function () {
    $document = storedRuleDocument(
        RuleDocument::factory()->processando()->for($this->condominium)->create(),
        (string) file_get_contents(base_path('tests/Fixtures/sem-texto.pdf')),
    );

    ExtractRuleArticles::dispatchSync($document);

    $document->refresh();

    expect($document->hasStatus(DocumentStatus::FALHA_EXTRACAO))->toBeTrue()
        ->and($document->processing_error)->toBe('Não foi possível extrair texto do PDF (pode ser um documento escaneado).')
        ->and(RuleArticle::query()->count())->toBe(0);

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents')
        ->assertSee('Falha na extração')
        ->assertSee('Não foi possível extrair texto do PDF (pode ser um documento escaneado).')
        ->assertSee('Reenviar PDF');
});

test('a parser exception moves the document to falha_extracao with the exception message', function () {
    $document = storedRuleDocument(
        RuleDocument::factory()->processando()->for($this->condominium)->create(),
        'isto não é um PDF',
    );

    ExtractRuleArticles::dispatchSync($document);

    $document->refresh();

    expect($document->hasStatus(DocumentStatus::FALHA_EXTRACAO))->toBeTrue()
        ->and($document->processing_error)->toContain('PDF')
        ->and(RuleArticle::query()->count())->toBe(0);
});

test('re-uploading a document with falha_extracao replaces the file and processes it again', function () {
    Queue::fake();

    $document = storedRuleDocument(
        RuleDocument::factory()->falhaExtracao()->for($this->condominium)->create([
            'file_path' => "rule-documents/{$this->condominium->id}/escaneado.pdf",
        ]),
        (string) file_get_contents(base_path('tests/Fixtures/sem-texto.pdf')),
    );

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents', ['selectedDocumentId' => $document->id])
        ->call('openReuploadForm')
        ->assertSet('showReuploadForm', true)
        ->set('reuploadFile', fixturePdf('regimento.pdf'))
        ->call('reupload')
        ->assertHasNoErrors()
        ->assertSet('showReuploadForm', false)
        ->assertDispatched('toast', type: 'success');

    $document->refresh();

    expect($document->hasStatus(DocumentStatus::PROCESSANDO))->toBeTrue()
        ->and($document->processing_error)->toBeNull()
        ->and($document->file_path)->not->toBe("rule-documents/{$this->condominium->id}/escaneado.pdf")
        ->and($document->file_path)->toStartWith("rule-documents/{$this->condominium->id}/");

    Storage::disk(RuleDocumentService::DISK)->assertMissing("rule-documents/{$this->condominium->id}/escaneado.pdf");
    Storage::disk(RuleDocumentService::DISK)->assertExists($document->file_path);
    Queue::assertPushed(ExtractRuleArticles::class, fn (ExtractRuleArticles $job) => $job->document->is($document));

    app()->call([new ExtractRuleArticles($document), 'handle']);

    expect($document->refresh()->hasStatus(DocumentStatus::EM_REVISAO))->toBeTrue()
        ->and(RuleArticle::query()->where('rule_document_id', $document->id)->count())->toBe(2);
});

test('only documents with falha_extracao accept a new PDF', function () {
    Queue::fake();

    $document = RuleDocument::factory()->emRevisao()->for($this->condominium)->create();
    $originalPath = $document->file_path;

    actingInPanel($this->sindico);

    Livewire::test('pages::rule-documents', ['selectedDocumentId' => $document->id])
        ->set('reuploadFile', fixturePdf('regimento.pdf'))
        ->call('reupload')
        ->assertDispatched('toast', type: 'error', message: 'Só documentos com falha na extração aceitam um novo PDF.');

    expect($document->refresh()->file_path)->toBe($originalPath)
        ->and($document->hasStatus(DocumentStatus::EM_REVISAO))->toBeTrue();

    Queue::assertNothingPushed();
});

test('the PDF is downloaded through a private route of the current condominium', function () {
    $document = storedRuleDocument(
        RuleDocument::factory()->publicado()->for($this->condominium)->create(['title' => 'Regimento Interno 2024']),
        '%PDF-1.4 conteúdo',
    );

    $this->actingAs($this->sindico)
        ->get(route('rule-documents.download', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('regimento-interno-2024.pdf');
});

test('downloading a document of another condominium responds 404', function () {
    $otherDocument = storedRuleDocument(
        RuleDocument::factory()->publicado()->for(Condominium::factory())->create(),
        '%PDF-1.4 conteúdo',
    );

    $this->actingAs($this->sindico)
        ->get(route('rule-documents.download', $otherDocument))
        ->assertNotFound();
});

test('zelador receives 403 on the screen, the upload and the download', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();
    $document = storedRuleDocument(RuleDocument::factory()->publicado()->for($this->condominium)->create(), '%PDF-1.4');

    $this->actingAs($zelador)->get(route('rule-documents.index'))->assertForbidden();
    $this->actingAs($zelador)->get(route('rule-documents.download', $document))->assertForbidden();

    actingInPanel($zelador);

    Livewire::test('pages::rule-documents')->assertForbidden();
});
