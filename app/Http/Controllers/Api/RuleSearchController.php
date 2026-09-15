<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RuleSearchRequest;
use App\Http\Resources\Api\RuleSearchResultResource;
use App\Services\RuleDocuments\RuleSearchService;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/rules/search — articles of the published regimento/convenção of the token's condominium
 * that answer the question, most similar first. No result above the threshold responds `results: []`.
 */
class RuleSearchController extends Controller
{
    public function __invoke(RuleSearchRequest $request, RuleSearchService $ruleSearchService, ToolCallContext $toolCallContext): JsonResponse
    {
        $results = $ruleSearchService->search($request->string('query')->trim()->toString(), $request->resultLimit())['results'];

        if ($results === []) {
            $toolCallContext->markEmpty();
        }

        $toolCallContext->addArticles(array_map(fn (array $result): int => $result['article']->id, $results));

        return response()->json([
            'results' => RuleSearchResultResource::collection($results)->resolve(),
        ]);
    }
}
