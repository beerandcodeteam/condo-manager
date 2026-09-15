<?php

namespace App\Services\RuleDocuments;

use App\Exceptions\RuleDocumentActionBlockedException;
use App\Jobs\ExtractRuleArticles;
use App\Jobs\IndexRuleArticles;
use App\Models\Condominium;
use App\Models\DocumentStatus;
use App\Models\DocumentType;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Lifecycle of regimento/convenção documents: upload and extraction into articles, review, publication
 * (indexing) and replacement of the previously published document of the same type.
 */
class RuleDocumentService
{
    /**
     * Private disk where the original PDFs are stored, under `rule-documents/{condominium_id}/`.
     */
    public const DISK = 'local';

    public const EMPTY_TEXT_ERROR = 'Não foi possível extrair texto do PDF (pode ser um documento escaneado).';

    /**
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        DocumentType::REGIMENTO => 'Regimento',
        DocumentType::CONVENCAO => 'Convenção',
    ];

    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        DocumentStatus::PROCESSANDO => 'Processando',
        DocumentStatus::EM_REVISAO => 'Em revisão',
        DocumentStatus::FALHA_EXTRACAO => 'Falha na extração',
        DocumentStatus::INDEXANDO => 'Indexando',
        DocumentStatus::FALHA_INDEXACAO => 'Falha na indexação',
        DocumentStatus::PUBLICADO => 'Publicado',
        DocumentStatus::SUBSTITUIDO => 'Substituído',
    ];

    /**
     * Store the PDF and queue the extraction of its articles; the document starts as `processando`.
     *
     * @throws Throwable
     */
    public function upload(Condominium $condominium, User $uploader, string $type, string $title, UploadedFile $file): RuleDocument
    {
        $path = $this->storeFile($condominium, $file);

        try {
            $document = new RuleDocument([
                'document_type_id' => DocumentType::idFor($type),
                'document_status_id' => DocumentStatus::idFor(DocumentStatus::PROCESSANDO),
                'title' => trim($title),
                'file_path' => $path,
                'uploaded_by_user_id' => $uploader->id,
            ]);

            $document->condominium_id = $condominium->id;
            $document->save();
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }

        ExtractRuleArticles::dispatch($document);

        return $document;
    }

    /**
     * Replace the PDF of a document whose extraction failed and queue the extraction again.
     *
     * @throws RuleDocumentActionBlockedException
     */
    public function reupload(RuleDocument $document, UploadedFile $file): RuleDocument
    {
        if (! $document->hasStatus(DocumentStatus::FALHA_EXTRACAO)) {
            throw new RuleDocumentActionBlockedException('Só documentos com falha na extração aceitam um novo PDF.');
        }

        $previousPath = $document->file_path;
        $path = $this->storeFile($document->condominium, $file);

        DB::transaction(function () use ($document, $path): void {
            $document->articles()->delete();

            $document->forceFill([
                'document_status_id' => DocumentStatus::idFor(DocumentStatus::PROCESSANDO),
                'file_path' => $path,
                'processing_error' => null,
            ])->save();
        });

        if ($previousPath !== $path) {
            Storage::disk(self::DISK)->delete($previousPath);
        }

        ExtractRuleArticles::dispatch($document);

        return $document;
    }

    /**
     * Publish a reviewed document (or retry a failed indexing): the document becomes `indexando` and the
     * embeddings job makes it `publicado` once every article is indexed.
     *
     * @throws RuleDocumentActionBlockedException
     */
    public function publish(RuleDocument $document, User $publisher): RuleDocument
    {
        DB::transaction(function () use ($document, $publisher): void {
            $lockedDocument = RuleDocument::query()->withoutGlobalScopes()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if (! $lockedDocument->hasStatus(DocumentStatus::EM_REVISAO, DocumentStatus::FALHA_INDEXACAO)) {
                throw new RuleDocumentActionBlockedException('Só documentos em revisão ou com falha na indexação podem ser publicados.');
            }

            if (! RuleArticle::query()->withoutGlobalScopes()->where('rule_document_id', $document->id)->exists()) {
                throw new RuleDocumentActionBlockedException('Adicione ao menos um artigo antes de publicar.');
            }

            $document->forceFill([
                'document_status_id' => DocumentStatus::idFor(DocumentStatus::INDEXANDO),
                'published_by_user_id' => $publisher->id,
                'processing_error' => null,
            ])->save();
        });

        IndexRuleArticles::dispatch($document);

        return $document;
    }

    /**
     * Mark the document as `publicado` once all its articles have embeddings, replacing the published
     * document of the same type in the condominium. Returns false while some article is not indexed.
     */
    public function completePublication(RuleDocument $document): bool
    {
        return DB::transaction(function () use ($document): bool {
            Condominium::query()->whereKey($document->condominium_id)->lockForUpdate()->firstOrFail();

            $lockedDocument = RuleDocument::query()->withoutGlobalScopes()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if (! $lockedDocument->hasStatus(DocumentStatus::INDEXANDO)) {
                return false;
            }

            $articles = RuleArticle::query()->withoutGlobalScopes()->where('rule_document_id', $document->id);

            if (! (clone $articles)->exists() || (clone $articles)->whereNull('embedding')->exists()) {
                return false;
            }

            RuleDocument::query()
                ->withoutGlobalScopes()
                ->where('condominium_id', $document->condominium_id)
                ->where('document_type_id', $document->document_type_id)
                ->whereKeyNot($document->id)
                ->where('document_status_id', DocumentStatus::idFor(DocumentStatus::PUBLICADO))
                ->update([
                    'document_status_id' => DocumentStatus::idFor(DocumentStatus::SUBSTITUIDO),
                    'updated_at' => now(),
                ]);

            $document->forceFill([
                'document_status_id' => DocumentStatus::idFor(DocumentStatus::PUBLICADO),
                'published_at' => now(),
                'processing_error' => null,
            ])->save();

            return true;
        });
    }

    /**
     * The indexing failed: the document stays out of the search and can be published again.
     */
    public function failIndexing(RuleDocument $document, string $message): void
    {
        RuleDocument::query()
            ->withoutGlobalScopes()
            ->whereKey($document->id)
            ->where('document_status_id', DocumentStatus::idFor(DocumentStatus::INDEXANDO))
            ->update([
                'document_status_id' => DocumentStatus::idFor(DocumentStatus::FALHA_INDEXACAO),
                'processing_error' => $message,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array{reference: string, title: string|null, body: string}  $attributes
     *
     * @throws RuleDocumentActionBlockedException
     */
    public function updateArticle(RuleArticle $article, array $attributes): RuleArticle
    {
        return DB::transaction(function () use ($article, $attributes): RuleArticle {
            $this->lockForReview($article->rule_document_id);

            $article->update($this->articleData($attributes));

            return $article;
        });
    }

    /**
     * Append a new article at the end of the document.
     *
     * @param  array{reference: string, title: string|null, body: string}  $attributes
     *
     * @throws RuleDocumentActionBlockedException
     */
    public function addArticle(RuleDocument $document, array $attributes): RuleArticle
    {
        return DB::transaction(function () use ($document, $attributes): RuleArticle {
            $this->lockForReview($document->id);

            $article = new RuleArticle([
                ...$this->articleData($attributes),
                'rule_document_id' => $document->id,
                'position' => (int) RuleArticle::query()->withoutGlobalScopes()->where('rule_document_id', $document->id)->max('position') + 1,
            ]);

            $article->condominium_id = $document->condominium_id;
            $article->save();

            return $article;
        });
    }

    /**
     * @throws RuleDocumentActionBlockedException
     */
    public function deleteArticle(RuleArticle $article): void
    {
        DB::transaction(function () use ($article): void {
            $document = $this->lockForReview($article->rule_document_id);

            $article->delete();

            $this->renumber($document, $this->orderedArticleIds($document));
        });
    }

    /**
     * Move the article one position up (`-1`) or down (`1`), keeping positions contiguous from 1.
     *
     * @throws RuleDocumentActionBlockedException
     */
    public function moveArticle(RuleArticle $article, int $direction): void
    {
        DB::transaction(function () use ($article, $direction): void {
            $document = $this->lockForReview($article->rule_document_id);

            $articleIds = $this->orderedArticleIds($document);
            $currentIndex = array_search($article->id, $articleIds, true);
            $targetIndex = $currentIndex === false ? false : $currentIndex + ($direction < 0 ? -1 : 1);

            if ($targetIndex !== false && isset($articleIds[$targetIndex])) {
                [$articleIds[$currentIndex], $articleIds[$targetIndex]] = [$articleIds[$targetIndex], $articleIds[$currentIndex]];
            }

            $this->renumber($document, $articleIds);
        });
    }

    /**
     * Label shown in the panel for the document status slug.
     */
    public static function statusLabel(string $statusSlug): string
    {
        return self::STATUS_LABELS[$statusSlug] ?? $statusSlug;
    }

    /**
     * Lock the document row for the current transaction and make sure it is still in review: articles of
     * published and replaced documents are read-only.
     *
     * @throws RuleDocumentActionBlockedException
     */
    private function lockForReview(int $documentId): RuleDocument
    {
        $document = RuleDocument::query()->withoutGlobalScopes()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        if (! $document->hasStatus(DocumentStatus::EM_REVISAO)) {
            throw new RuleDocumentActionBlockedException('Os artigos só podem ser alterados enquanto o documento está em revisão.');
        }

        return $document;
    }

    /**
     * @return array<int, int>
     */
    private function orderedArticleIds(RuleDocument $document): array
    {
        return RuleArticle::query()
            ->withoutGlobalScopes()
            ->where('rule_document_id', $document->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id'])
            ->map(fn (RuleArticle $article): int => $article->id)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $articleIds  article ids in the new order
     */
    private function renumber(RuleDocument $document, array $articleIds): void
    {
        foreach (array_values($articleIds) as $index => $articleId) {
            RuleArticle::query()
                ->withoutGlobalScopes()
                ->where('rule_document_id', $document->id)
                ->whereKey($articleId)
                ->where('position', '!=', $index + 1)
                ->update(['position' => $index + 1]);
        }
    }

    /**
     * @param  array{reference: string, title: string|null, body: string}  $attributes
     * @return array{reference: string, title: string|null, body: string}
     */
    private function articleData(array $attributes): array
    {
        $title = trim((string) $attributes['title']);

        return [
            'reference' => trim($attributes['reference']),
            'title' => $title === '' ? null : $title,
            'body' => trim($attributes['body']),
        ];
    }

    /**
     * @throws RuntimeException
     */
    private function storeFile(Condominium $condominium, UploadedFile $file): string
    {
        $path = $file->store("rule-documents/{$condominium->id}", self::DISK);

        if ($path === false) {
            throw new RuntimeException('Não foi possível salvar o PDF.');
        }

        return $path;
    }
}
