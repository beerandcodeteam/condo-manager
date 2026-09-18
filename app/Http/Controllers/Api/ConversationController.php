<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ConversationAppendRequest;
use App\Http\Requests\Api\ConversationHistoryRequest;
use App\Http\Resources\Api\AgentMediaResource;
use App\Http\Resources\Api\AgentMessageResource;
use App\Services\Integration\ConversationService;
use App\Services\Integration\MediaService;
use Illuminate\Http\JsonResponse;

/**
 * Conversation memory of the WhatsApp agent. Read by the n8n flow before each turn and written
 * after, so the history belongs to the condominium instead of to n8n.
 */
class ConversationController extends Controller
{
    /**
     * GET /api/v1/conversations — the last messages exchanged with a phone, oldest first.
     */
    public function index(
        ConversationHistoryRequest $request,
        ConversationService $conversationService,
        MediaService $mediaService,
    ): JsonResponse {
        $phone = $request->string('phone')->toString();

        $messages = $conversationService->history(
            $phone,
            $request->integer('limit') ?: (int) config('condo.conversations.default_limit'),
        );

        return response()->json([
            'messages' => AgentMessageResource::collection($messages)->resolve(),
            // Mídias que o morador mandou e nenhum chamado consumiu: o agente pode anexá-las
            // mesmo que tenham chegado algumas mensagens antes.
            'pending_media' => AgentMediaResource::collection($mediaService->pending($phone))->resolve(),
        ]);
    }

    /**
     * POST /api/v1/conversations — append one turn (the resident's message and the agent's answer).
     */
    public function store(ConversationAppendRequest $request, ConversationService $conversationService): JsonResponse
    {
        /** @var list<array{role: string, content: string}> $messages */
        $messages = $request->validated('messages');

        $stored = $conversationService->append($request->string('phone')->toString(), $messages);

        return response()->json(['messages' => AgentMessageResource::collection($stored)->resolve()], 201);
    }
}
