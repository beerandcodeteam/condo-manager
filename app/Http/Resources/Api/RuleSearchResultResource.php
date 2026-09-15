<?php

namespace App\Http\Resources\Api;

use App\Models\RuleArticle;
use App\Models\RuleDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rule search result as returned to the agent: the citable article, its document and the similarity score.
 *
 * @property array{article: RuleArticle, document: RuleDocument, score: float} $resource
 */
class RuleSearchResultResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{document: array{id: int, type: string, title: string}, article: array{id: int, reference: string, title: string|null}, text: string, score: float}
     */
    public function toArray(Request $request): array
    {
        $article = $this->resource['article'];
        $document = $this->resource['document'];

        return [
            'document' => [
                'id' => $document->id,
                'type' => $document->documentType->slug,
                'title' => $document->title,
            ],
            'article' => [
                'id' => $article->id,
                'reference' => $article->reference,
                'title' => $article->title,
            ],
            'text' => $article->body,
            'score' => $this->resource['score'],
        ];
    }
}
