<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\NoticeResource;
use App\Support\Tenancy\CurrentCondominium;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;

/**
 * Notices of the token's condominium, for the agent tool `consultar_comunicados`.
 */
class NoticeController extends Controller
{
    /**
     * GET /api/v1/notices — every active notice of the token's condominium, most recently updated first.
     * No search or filter: query parameters are ignored.
     */
    public function index(CurrentCondominium $currentCondominium, ToolCallContext $toolCallContext): JsonResponse
    {
        $notices = $currentCondominium->getOrFail()
            ->notices()
            ->active()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        if ($notices->isEmpty()) {
            $toolCallContext->markEmpty();
        }

        return response()->json([
            'notices' => NoticeResource::collection($notices)->resolve(),
        ]);
    }
}
