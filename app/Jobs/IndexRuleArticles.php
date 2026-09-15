<?php

namespace App\Jobs;

use App\Models\DocumentStatus;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Services\RuleDocuments\RuleDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Embeddings;
use RuntimeException;
use Throwable;

/**
 * Generates the embeddings of a document's articles in batches and publishes the document when all of
 * them are indexed. Any failure leaves the document as `falha_indexacao`, out of the search.
 */
class IndexRuleArticles implements ShouldQueue
{
    use Queueable;

    public const BATCH_SIZE = 100;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public RuleDocument $document) {}

    /**
     * Execute the job.
     */
    public function handle(RuleDocumentService $ruleDocumentService): void
    {
        if (! $this->document->hasStatus(DocumentStatus::INDEXANDO)) {
            return;
        }

        try {
            RuleArticle::query()
                ->withoutGlobalScopes()
                ->where('rule_document_id', $this->document->id)
                ->whereNull('embedding')
                ->select(['id', 'reference', 'title', 'body'])
                ->chunkById(self::BATCH_SIZE, fn (Collection $articles) => $this->embed($articles));

            if (! $ruleDocumentService->completePublication($this->document)) {
                throw new RuntimeException('Nem todos os artigos foram indexados.');
            }
        } catch (Throwable $exception) {
            report($exception);

            $ruleDocumentService->failIndexing($this->document, $exception->getMessage() ?: 'Falha ao gerar os embeddings dos artigos.');
        }
    }

    /**
     * The worker failed or timed out: the síndico can try to publish again.
     */
    public function failed(?Throwable $exception): void
    {
        app(RuleDocumentService::class)->failIndexing($this->document, $exception?->getMessage() ?: 'Falha ao gerar os embeddings dos artigos.');
    }

    /**
     * Text embedded for an article: reference, title and body.
     */
    public static function embeddingInput(RuleArticle $article): string
    {
        return collect([$article->reference, $article->title, $article->body])->filter(fn (?string $part): bool => filled($part))->implode("\n");
    }

    /**
     * @param  Collection<int, RuleArticle>  $articles
     */
    private function embed(Collection $articles): void
    {
        $response = Embeddings::for($articles->map(fn (RuleArticle $article): string => self::embeddingInput($article))->values()->all())
            ->dimensions((int) config('condo.rag.embedding_dimensions'))
            ->generate();

        if (count($response->embeddings) !== $articles->count()) {
            throw new RuntimeException('O provedor de embeddings retornou uma quantidade diferente de vetores.');
        }

        $embeddedAt = now();

        foreach ($articles->values() as $index => $article) {
            RuleArticle::query()->withoutGlobalScopes()->whereKey($article->id)->update([
                'embedding' => json_encode(array_values($response->embeddings[$index])),
                'embedded_at' => $embeddedAt,
            ]);
        }
    }
}
