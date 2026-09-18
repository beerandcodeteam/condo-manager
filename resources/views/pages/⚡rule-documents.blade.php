<?php

use App\Exceptions\RuleDocumentActionBlockedException;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\DocumentStatus;
use App\Models\DocumentType;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Services\RuleDocuments\RuleDocumentService;
use App\Services\RuleDocuments\RuleSearchService;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Layout('layouts::app')] #[Title('Regimento')] class extends Component
{
    use WithFileUploads;

    /**
     * Clickable sample questions of the "Testar pergunta" card.
     *
     * @var list<string>
     */
    public const SAMPLE_QUESTIONS = [
        'Posso fechar a sacada?',
        'Até que horas pode barulho?',
        'Posso ter cachorro grande?',
    ];

    public const TEST_EXCERPT_LENGTH = 200;

    #[Url(as: 'documento')]
    public ?int $selectedDocumentId = null;

    public bool $showUploadForm = false;

    public string $uploadType = DocumentType::REGIMENTO;

    public string $uploadTitle = '';

    public ?TemporaryUploadedFile $uploadFile = null;

    public bool $showReuploadForm = false;

    public ?TemporaryUploadedFile $reuploadFile = null;

    public ?int $editingArticleId = null;

    public bool $isAddingArticle = false;

    public string $articleReference = '';

    public string $articleTitle = '';

    public string $articleBody = '';

    public string $testQuestion = '';

    /**
     * Outcome of the last "Testar pergunta": the best article found, or `article: null` when none.
     *
     * @var array{question: string, article: array{citation: string, excerpt: string, score: float}|null, latency_ms: int}|null
     */
    public ?array $testResult = null;

    public function mount(): void
    {
        $this->authorize('knowledge.manage');
    }

    /**
     * Documents of the condominium, most recently uploaded first.
     *
     * @return Collection<int, RuleDocument>
     */
    #[Computed]
    public function documents(): Collection
    {
        return $this->condominium()->ruleDocuments()
            ->with(['documentType', 'documentStatus'])
            ->withCount('articles')
            ->latest()
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The selected document, or the first one of the list.
     */
    #[Computed]
    public function selectedDocument(): ?RuleDocument
    {
        return $this->documents->firstWhere('id', $this->selectedDocumentId) ?? $this->documents->first();
    }

    /**
     * Articles of the selected document in review order.
     *
     * @return Collection<int, RuleArticle>
     */
    #[Computed]
    public function articles(): Collection
    {
        return $this->selectedDocument?->articles()
            ->select(['id', 'condominium_id', 'rule_document_id', 'reference', 'title', 'body', 'position', 'embedded_at'])
            ->get() ?? new Collection;
    }

    /**
     * Articles of the selected document that already have an embedding ("Indexado · N trechos").
     */
    #[Computed]
    public function indexedArticlesCount(): int
    {
        return $this->selectedDocument?->articles()->whereNotNull('embedding')->count() ?? 0;
    }

    /**
     * Times each article was returned to the agent ("citado N×"), for published and replaced documents.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function citationCounts(): array
    {
        if (! $this->selectedDocument?->hasStatus(DocumentStatus::PUBLICADO, DocumentStatus::SUBSTITUIDO)) {
            return [];
        }

        return $this->articles
            ->mapWithKeys(fn (RuleArticle $article): array => [
                $article->id => AgentToolCall::query()->forArticle($article->id)->count(),
            ])
            ->all();
    }

    /**
     * Whether the selected document is still being processed, so the screen refreshes every 5 seconds.
     */
    #[Computed]
    public function isProcessing(): bool
    {
        return (bool) $this->selectedDocument?->hasStatus(DocumentStatus::PROCESSANDO, DocumentStatus::INDEXANDO);
    }

    /**
     * Whether the condominium has a published regimento or convenção to search in.
     */
    #[Computed]
    public function hasPublishedDocument(): bool
    {
        return $this->condominium()->ruleDocuments()->published()->exists();
    }

    /**
     * Run the same search as the agent API (without logging a tool call) and keep the best article.
     */
    public function askQuestion(RuleSearchService $ruleSearchService): void
    {
        $this->authorize('knowledge.manage');

        $validated = $this->validate([
            'testQuestion' => ['required', 'string', 'max:'.config('condo.rag.max_query_length')],
        ], attributes: [
            'testQuestion' => 'pergunta',
        ]);

        unset($this->hasPublishedDocument);

        if (! $this->hasPublishedDocument) {
            $this->testResult = null;

            return;
        }

        $question = trim($validated['testQuestion']);
        $search = $ruleSearchService->search($question, (int) config('condo.rag.default_limit'));
        $best = $search['results'][0] ?? null;

        $this->testResult = [
            'question' => $question,
            'article' => $best === null ? null : [
                'citation' => $best['document']->title.' · '.$best['article']->reference,
                'excerpt' => Str::limit(Str::squish($best['article']->body), self::TEST_EXCERPT_LENGTH - 1, '…'),
                'score' => $best['score'],
            ],
            'latency_ms' => $search['latency_ms'],
        ];
    }

    public function askSampleQuestion(string $question, RuleSearchService $ruleSearchService): void
    {
        $this->testQuestion = $question;

        $this->askQuestion($ruleSearchService);
    }

    public function selectDocument(int $documentId): void
    {
        $this->selectedDocumentId = $this->findDocument($documentId)->id;

        $this->resetArticleForm();
        $this->refreshDocuments();
    }

    /**
     * Publish the reviewed document or retry a failed indexing.
     */
    public function publish(RuleDocumentService $ruleDocumentService): void
    {
        $this->authorize('knowledge.manage');

        try {
            $ruleDocumentService->publish($this->selectedDocumentOrFail(), Auth::user());
        } catch (RuleDocumentActionBlockedException $exception) {
            $this->failAction($exception);

            return;
        }

        $this->resetArticleForm();
        $this->refreshDocuments();

        $this->dispatch('toast', type: 'success', message: 'Publicação iniciada. Os artigos estão sendo indexados.');
    }

    public function editArticle(int $articleId): void
    {
        $this->authorize('knowledge.manage');

        $article = $this->findArticle($articleId);

        $this->resetArticleForm();
        $this->editingArticleId = $article->id;
        $this->articleReference = $article->reference;
        $this->articleTitle = (string) $article->title;
        $this->articleBody = $article->body;
    }

    public function createArticle(): void
    {
        $this->authorize('knowledge.manage');

        $this->resetArticleForm();
        $this->isAddingArticle = true;
    }

    public function cancelArticleForm(): void
    {
        $this->resetArticleForm();
    }

    public function saveArticle(RuleDocumentService $ruleDocumentService): void
    {
        $this->authorize('knowledge.manage');

        $validated = $this->validate([
            'articleReference' => ['required', 'string', 'max:255'],
            'articleTitle' => ['nullable', 'string', 'max:255'],
            'articleBody' => ['required', 'string'],
        ], attributes: [
            'articleReference' => 'referência',
            'articleTitle' => 'título',
            'articleBody' => 'texto',
        ]);

        $attributes = [
            'reference' => $validated['articleReference'],
            'title' => $validated['articleTitle'],
            'body' => $validated['articleBody'],
        ];

        $isCreating = $this->editingArticleId === null;

        try {
            if ($isCreating) {
                $ruleDocumentService->addArticle($this->selectedDocumentOrFail(), $attributes);
            } else {
                $ruleDocumentService->updateArticle($this->findArticle($this->editingArticleId), $attributes);
            }
        } catch (RuleDocumentActionBlockedException $exception) {
            $this->failAction($exception);

            return;
        }

        $this->resetArticleForm();
        $this->refreshDocuments();

        $this->dispatch('toast', type: 'success', message: $isCreating ? 'Artigo adicionado.' : 'Artigo salvo.');
    }

    public function deleteArticle(int $articleId, RuleDocumentService $ruleDocumentService): void
    {
        $this->authorize('knowledge.manage');

        try {
            $ruleDocumentService->deleteArticle($this->findArticle($articleId));
        } catch (RuleDocumentActionBlockedException $exception) {
            $this->failAction($exception);

            return;
        }

        if ($this->editingArticleId === $articleId) {
            $this->resetArticleForm();
        }

        $this->refreshDocuments();

        $this->dispatch('toast', type: 'success', message: 'Artigo excluído.');
    }

    public function moveArticle(int $articleId, int $direction, RuleDocumentService $ruleDocumentService): void
    {
        $this->authorize('knowledge.manage');

        try {
            $ruleDocumentService->moveArticle($this->findArticle($articleId), $direction);
        } catch (RuleDocumentActionBlockedException $exception) {
            $this->failAction($exception);

            return;
        }

        $this->refreshDocuments();
    }

    public function openUploadForm(): void
    {
        $this->authorize('knowledge.manage');

        $this->reset('uploadType', 'uploadTitle', 'uploadFile');
        $this->resetValidation();
        $this->showUploadForm = true;
    }

    public function uploadDocument(RuleDocumentService $ruleDocumentService): void
    {
        $this->authorize('knowledge.manage');

        $validated = $this->validate([
            'uploadType' => ['required', 'string', Rule::in(array_keys(RuleDocumentService::TYPE_LABELS))],
            'uploadTitle' => ['required', 'string', 'max:255'],
            'uploadFile' => ['required', 'file', 'mimes:pdf'],
        ], attributes: [
            'uploadType' => 'tipo',
            'uploadTitle' => 'título',
            'uploadFile' => 'arquivo',
        ]);

        $document = $ruleDocumentService->upload(
            $this->condominium(),
            Auth::user(),
            $validated['uploadType'],
            $validated['uploadTitle'],
            $validated['uploadFile'],
        );

        $this->showUploadForm = false;
        $this->reset('uploadType', 'uploadTitle', 'uploadFile');
        $this->selectedDocumentId = $document->id;
        $this->refreshDocuments();

        $this->dispatch('toast', type: 'success', message: 'PDF enviado. Os artigos estão sendo extraídos.');
    }

    public function openReuploadForm(): void
    {
        $this->authorize('knowledge.manage');

        $this->reset('reuploadFile');
        $this->resetValidation();
        $this->showReuploadForm = true;
    }

    public function reupload(RuleDocumentService $ruleDocumentService): void
    {
        $this->authorize('knowledge.manage');

        $validated = $this->validate([
            'reuploadFile' => ['required', 'file', 'mimes:pdf'],
        ], attributes: [
            'reuploadFile' => 'arquivo',
        ]);

        $document = $this->selectedDocumentOrFail();

        try {
            $ruleDocumentService->reupload($document, $validated['reuploadFile']);
        } catch (RuleDocumentActionBlockedException $exception) {
            $this->showReuploadForm = false;
            $this->refreshDocuments();
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->showReuploadForm = false;
        $this->reset('reuploadFile');
        $this->refreshDocuments();

        $this->dispatch('toast', type: 'success', message: 'PDF reenviado. Os artigos estão sendo extraídos.');
    }

    /**
     * Meta line of a document button, e.g. "Regimento · Publicado · 48 artigos".
     */
    public function documentMeta(RuleDocument $document): string
    {
        $articlesCount = (int) $document->articles_count;

        return collect([
            RuleDocumentService::TYPE_LABELS[$document->documentType->slug] ?? $document->documentType->name,
            RuleDocumentService::statusLabel($document->documentStatus->slug),
            $articlesCount === 1 ? '1 artigo' : "{$articlesCount} artigos",
        ])->implode(' · ');
    }

    private function selectedDocumentOrFail(): RuleDocument
    {
        $document = $this->selectedDocument;

        abort_if($document === null, 404);

        return $this->findDocument($document->id);
    }

    /**
     * An article of the selected document.
     */
    private function findArticle(int $articleId): RuleArticle
    {
        return $this->selectedDocumentOrFail()->articles()->findOrFail($articleId);
    }

    private function failAction(RuleDocumentActionBlockedException $exception): void
    {
        $this->resetArticleForm();
        $this->refreshDocuments();

        $this->dispatch('toast', type: 'error', message: $exception->getMessage());
    }

    private function resetArticleForm(): void
    {
        $this->reset('editingArticleId', 'isAddingArticle', 'articleReference', 'articleTitle', 'articleBody');
        $this->resetValidation(['articleReference', 'articleTitle', 'articleBody']);
    }

    private function findDocument(int $documentId): RuleDocument
    {
        return $this->condominium()->ruleDocuments()->findOrFail($documentId);
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }

    private function refreshDocuments(): void
    {
        unset($this->documents, $this->selectedDocument, $this->articles, $this->indexedArticlesCount, $this->citationCounts, $this->isProcessing, $this->hasPublishedDocument);
    }
};
?>

@php
    $document = $this->selectedDocument;
    $statusSlug = $document?->documentStatus->slug;
@endphp

<div
    class="grid max-w-[1300px] grid-cols-[240px_1fr_340px] items-start gap-3.5"
    @if ($this->isProcessing) wire:poll.5s @endif
>
    <div class="flex flex-col gap-2" data-document-list>
        @foreach ($this->documents as $item)
            <button
                type="button"
                wire:key="document-{{ $item->id }}"
                wire:click="selectDocument({{ $item->id }})"
                @class([
                    'rounded-[12px] border px-3.5 py-3 text-left transition-colors hover:bg-white',
                    'border-line-strong bg-white' => $item->id === $document?->id,
                    'border-transparent bg-transparent' => $item->id !== $document?->id,
                ])
                data-document="{{ $item->id }}"
            >
                <span class="block font-semibold break-words">{{ $item->title }}</span>
                <span class="mt-0.5 block text-[12px] text-ink-secondary">{{ $this->documentMeta($item) }}</span>
            </button>
        @endforeach

        <button
            type="button"
            wire:click="openUploadForm"
            class="rounded-[12px] border border-dashed border-black/15 bg-transparent px-3.5 py-3 text-left font-medium text-accent transition-colors hover:bg-white hover:text-accent-hover"
        >+ Enviar PDF</button>
    </div>

    <div class="min-w-0 overflow-hidden rounded-card border border-line bg-white shadow-card" data-document-panel>
        @if ($document === null)
            <x-ui.empty-state
                title="Nenhum documento enviado"
                description="Envie o PDF do regimento interno ou da convenção para o agente citar os artigos."
            >
                <x-ui.button size="sm" wire:click="openUploadForm">+ Enviar PDF</x-ui.button>
            </x-ui.empty-state>
        @else
            <div class="flex items-center gap-2.5 border-b border-black/5 px-[18px] py-3.5">
                <span class="min-w-0 flex-1 text-[14px] font-semibold break-words">{{ $document->title }}</span>

                <a href="{{ route('rule-documents.download', $document) }}" class="text-[12px] font-medium whitespace-nowrap text-accent hover:text-accent-hover" data-download>Baixar PDF</a>

                @if ($statusSlug === DocumentStatus::PUBLICADO)
                    <x-ui.pill variant="ok" data-status-pill>Indexado · {{ $this->indexedArticlesCount }} trechos</x-ui.pill>
                @elseif ($statusSlug === DocumentStatus::EM_REVISAO)
                    <x-ui.pill variant="warn" data-status-pill>{{ RuleDocumentService::statusLabel($statusSlug) }}</x-ui.pill>
                @elseif (in_array($statusSlug, [DocumentStatus::FALHA_EXTRACAO, DocumentStatus::FALHA_INDEXACAO], true))
                    <x-ui.pill variant="esc" data-status-pill>{{ RuleDocumentService::statusLabel($statusSlug) }}</x-ui.pill>
                @else
                    <x-ui.pill data-status-pill>{{ RuleDocumentService::statusLabel($statusSlug) }}</x-ui.pill>
                @endif
            </div>

            @if (in_array($statusSlug, [DocumentStatus::FALHA_EXTRACAO, DocumentStatus::FALHA_INDEXACAO], true))
                <div class="flex items-center gap-3 border-b border-black/5 bg-tag-esc-bg px-[18px] py-3 text-[12px] text-tag-esc-fg" role="alert" data-processing-error>
                    <span class="min-w-0 flex-1 break-words">{{ $document->processing_error }}</span>
                    @if ($statusSlug === DocumentStatus::FALHA_EXTRACAO)
                        <x-ui.button size="sm" variant="secondary" wire:click="openReuploadForm">Reenviar PDF</x-ui.button>
                    @else
                        <x-ui.button size="sm" variant="secondary" wire:click="publish">Tentar novamente</x-ui.button>
                    @endif
                </div>
            @endif

            @php
                $isReviewing = $statusSlug === DocumentStatus::EM_REVISAO;
            @endphp

            @if ($isReviewing)
                <div class="flex items-center gap-2 border-b border-black/5 bg-surface-soft px-[18px] py-2.5 text-[12px] text-ink-secondary">
                    <span class="min-w-0 flex-1">Revise referência, título e texto de cada artigo antes de publicar.</span>
                    <x-ui.button size="sm" variant="secondary" wire:click="createArticle" data-add-article>+ Artigo</x-ui.button>
                    <x-ui.button size="sm" wire:click="publish" :disabled="$this->articles->isEmpty()" data-publish>Publicar</x-ui.button>
                </div>
            @endif

            @forelse ($this->articles as $article)
                @if ($isReviewing && $editingArticleId === $article->id)
                    <div wire:key="article-form-{{ $article->id }}" class="border-b border-black/5 px-[18px] py-3.5" data-article-form="{{ $article->id }}">
                        @include('partials.rule-article-form', ['formId' => "rule-article-form-{$article->id}"])
                    </div>
                @else
                    <div wire:key="article-{{ $article->id }}" class="grid grid-cols-[64px_1fr_auto] gap-3.5 border-b border-black/5 px-[18px] py-3.5 last:border-b-0" data-article="{{ $article->id }}">
                        <span class="text-[12px] font-semibold break-words text-accent">{{ $article->reference }}</span>
                        <span class="min-w-0">
                            @if (filled($article->title))
                                <span class="block font-medium break-words">{{ $article->title }}</span>
                            @endif
                            <span class="mt-[3px] block text-[12px] whitespace-pre-line break-words text-ink-body">{{ $article->body }}</span>
                        </span>
                        <span class="flex items-start gap-2.5 text-[11px] whitespace-nowrap text-ink-tertiary">
                            @if ($isReviewing)
                                <button type="button" wire:click="moveArticle({{ $article->id }}, -1)" @disabled($loop->first) class="text-ink-secondary hover:text-ink disabled:opacity-40" title="Mover para cima" aria-label="Mover para cima" data-move-up>↑</button>
                                <button type="button" wire:click="moveArticle({{ $article->id }}, 1)" @disabled($loop->last) class="text-ink-secondary hover:text-ink disabled:opacity-40" title="Mover para baixo" aria-label="Mover para baixo" data-move-down>↓</button>
                                <button type="button" wire:click="editArticle({{ $article->id }})" class="font-medium text-accent hover:text-accent-hover" data-edit-article>Editar</button>
                                <button
                                    type="button"
                                    wire:click="deleteArticle({{ $article->id }})"
                                    wire:confirm="Excluir o artigo &quot;{{ $article->reference }}&quot;?"
                                    class="font-medium text-tag-esc-fg hover:underline"
                                    data-delete-article
                                >Excluir</button>
                            @elseif (isset($this->citationCounts[$article->id]))
                                <span data-citations>citado {{ $this->citationCounts[$article->id] }}×</span>
                            @endif
                        </span>
                    </div>
                @endif
            @empty
                @unless ($isReviewing && $isAddingArticle)
                    <p class="px-[18px] py-6 text-center text-[13px] text-ink-secondary">
                        @if ($statusSlug === DocumentStatus::PROCESSANDO)
                            Extraindo os artigos do PDF…
                        @elseif ($statusSlug === DocumentStatus::FALHA_EXTRACAO)
                            Nenhum artigo extraído.
                        @else
                            Nenhum artigo.
                        @endif
                    </p>
                @endunless
            @endforelse

            @if ($isReviewing && $isAddingArticle)
                <div wire:key="article-form-new" class="px-[18px] py-3.5" data-article-form="new">
                    @include('partials.rule-article-form', ['formId' => 'rule-article-form-new'])
                </div>
            @endif
        @endif
    </div>

    <div class="flex flex-col gap-3 rounded-card border border-line bg-white p-[18px] shadow-card" data-test-question>
        <div>
            <div class="text-[14px] font-semibold">Testar pergunta</div>
            <div class="text-[12px] text-ink-secondary">Simula o que o agente encontraria no WhatsApp.</div>
        </div>

        @if (! $this->hasPublishedDocument)
            <p class="rounded-control bg-tag-warn-bg px-3 py-2 text-[12px] text-tag-warn-fg" role="status" data-test-question-warning>Publique o regimento ou a convenção para testar.</p>
        @endif

        <form wire:submit="askQuestion" class="flex flex-col gap-1.5">
            <div class="flex gap-1.5">
                <x-ui.input wire:model="testQuestion" placeholder="Digite a pergunta do morador" aria-label="Pergunta" :disabled="! $this->hasPublishedDocument" />
                <x-ui.button type="submit" size="sm" loading="askQuestion" :disabled="! $this->hasPublishedDocument" data-test-question-submit>Testar</x-ui.button>
            </div>
            @error('testQuestion')
                <p class="text-[12px] text-tag-esc-fg" role="alert">{{ $message }}</p>
            @enderror
        </form>

        <div class="flex flex-wrap gap-1.5">
            @foreach ($this::SAMPLE_QUESTIONS as $sampleQuestion)
                <button
                    type="button"
                    wire:key="sample-question-{{ $loop->index }}"
                    wire:click="askSampleQuestion(@js($sampleQuestion))"
                    @disabled(! $this->hasPublishedDocument)
                    class="rounded-pill border border-line-strong bg-white px-2.5 py-[5px] text-[12px] text-ink-body transition-colors hover:bg-surface-soft disabled:cursor-not-allowed disabled:opacity-60"
                >{{ $sampleQuestion }}</button>
            @endforeach
        </div>

        @if ($testResult !== null)
            <div class="flex flex-col gap-2" data-test-result>
                <div class="max-w-[85%] self-end rounded-[14px] bg-[#dcf8c6] px-3 py-2 text-[13px] break-words">{{ $testResult['question'] }}</div>

                <div class="max-w-[92%] self-start rounded-[14px] bg-tag-grey-bg px-3 py-2.5 text-[13px] leading-normal">
                    @if ($testResult['article'] !== null)
                        <div class="rounded-control-sm border-l-2 border-accent bg-white px-2.5 py-2 text-[12px] break-words text-ink-body">
                            <b class="font-semibold text-accent">{{ $testResult['article']['citation'] }}</b> — {{ $testResult['article']['excerpt'] }}
                        </div>
                    @else
                        Nenhum artigo encontrado
                    @endif
                </div>

                <div class="font-mono text-[11px] text-ink-tertiary" data-test-result-footer>{{ collect([
                    'consultar_regimento',
                    $testResult['latency_ms'].' ms',
                    $testResult['article'] !== null ? 'score '.number_format($testResult['article']['score'], 2, '.', '') : null,
                ])->filter()->implode(' · ') }}</div>
            </div>
        @endif
    </div>

    <x-ui.modal wire:model="showUploadForm" title="Enviar PDF">
        <form id="rule-document-upload-form" wire:submit="uploadDocument" class="flex flex-col gap-3">
            <x-ui.field label="Tipo" name="uploadType" for="rule-document-type">
                <x-ui.select id="rule-document-type" wire:model="uploadType" required>
                    @foreach (RuleDocumentService::TYPE_LABELS as $typeSlug => $typeLabel)
                        <option value="{{ $typeSlug }}">{{ $typeLabel }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Título" name="uploadTitle" for="rule-document-title">
                <x-ui.input id="rule-document-title" wire:model="uploadTitle" maxlength="255" placeholder="Regimento Interno 2024" required />
            </x-ui.field>

            <x-ui.field label="Arquivo" name="uploadFile" hint="PDF com texto selecionável. Os artigos são extraídos para revisão antes de publicar.">
                <x-ui.file-drop wire:model="uploadFile" label="+ Selecionar PDF" accept=".pdf,application/pdf" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="rule-document-upload-form" loading="uploadDocument">Enviar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showReuploadForm" title="Substituir o PDF">
        <form id="rule-document-reupload-form" wire:submit="reupload" class="flex flex-col gap-3">
            <x-ui.field label="Arquivo" name="reuploadFile" hint="O novo PDF substitui o anterior e os artigos são extraídos novamente.">
                <x-ui.file-drop wire:model="reuploadFile" label="+ Selecionar PDF" accept=".pdf,application/pdf" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="rule-document-reupload-form" loading="reupload">Reenviar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
