<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CommonAreaResource;
use App\Support\Tenancy\CurrentCondominium;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/areas — active common areas of the token's condominium with their slots and advance rules.
 */
class AreaListController extends Controller
{
    public function __invoke(CurrentCondominium $currentCondominium, ToolCallContext $toolCallContext): JsonResponse
    {
        $areas = $currentCondominium->getOrFail()
            ->commonAreas()
            ->active()
            ->with('slots')
            ->orderBy('name')
            ->get();

        if ($areas->isEmpty()) {
            $toolCallContext->markEmpty();
        }

        return response()->json([
            'areas' => CommonAreaResource::collection($areas)->resolve(),
        ]);
    }
}
