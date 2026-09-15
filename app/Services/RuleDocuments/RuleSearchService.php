<?php

namespace App\Services\RuleDocuments;

use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Support\Tenancy\CurrentCondominium;
use Laravel\Ai\Embeddings;

/**
 * Semantic search over the articles of the published regimento/convenção of the current condominium.
 * Shared by the agent API (`consultar_regimento`) and the panel's "Testar pergunta".
 */
class RuleSearchService
{
    public function __construct(private CurrentCondominium $currentCondominium) {}

    /**
     * Articles above the minimum similarity, most similar first, with the elapsed time in ms.
     *
     * @return array{results: list<array{article: RuleArticle, document: RuleDocument, score: float}>, latency_ms: int}
     */
    public function search(string $query, int $limit): array
    {
        $startedAt = hrtime(true);
        $condominium = $this->currentCondominium->getOrFail();

        $publishedDocuments = RuleDocument::query()
            ->withoutGlobalScopes()
            ->where('condominium_id', $condominium->id)
            ->published()
            ->select('id');

        if (! (clone $publishedDocuments)->exists()) {
            return ['results' => [], 'latency_ms' => $this->elapsedMilliseconds($startedAt)];
        }

        $queryEmbedding = Embeddings::for([$query])
            ->dimensions((int) config('condo.rag.embedding_dimensions'))
            ->generate()
            ->embeddings[0];

        $articles = RuleArticle::query()
            ->withoutGlobalScopes()
            ->select(['id', 'condominium_id', 'rule_document_id', 'reference', 'title', 'body', 'position'])
            ->selectVectorDistance('embedding', $queryEmbedding, as: 'distance')
            ->where('condominium_id', $condominium->id)
            ->whereIn('rule_document_id', $publishedDocuments)
            ->whereNotNull('embedding')
            ->whereVectorSimilarTo('embedding', $queryEmbedding, minSimilarity: (float) config('condo.rag.min_similarity'))
            ->orderBy('id')
            ->limit($limit)
            ->with(['ruleDocument' => fn ($query) => $query->withoutGlobalScopes()->with('documentType')])
            ->get();

        $results = [];

        foreach ($articles as $article) {
            if ($article->ruleDocument === null) {
                continue;
            }

            $results[] = [
                'article' => $article,
                'document' => $article->ruleDocument,
                'score' => round(max(0.0, min(1.0, 1 - (float) $article->getAttribute('distance'))), 3),
            ];
        }

        return ['results' => $results, 'latency_ms' => $this->elapsedMilliseconds($startedAt)];
    }

    private function elapsedMilliseconds(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
