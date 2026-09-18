<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BufferAppendRequest;
use App\Http\Requests\Api\BufferFlushRequest;
use App\Services\Integration\MessageBuffer;
use Illuminate\Http\JsonResponse;

/**
 * Burst buffer of the WhatsApp agent: joins the fragments of someone who writes in several quick
 * messages, so the agent answers the thought once instead of each piece of it.
 */
class MessageBufferController extends Controller
{
    /**
     * POST /api/v1/conversations/buffer — add a fragment and get the sequence that identifies it.
     */
    public function append(BufferAppendRequest $request, MessageBuffer $messageBuffer): JsonResponse
    {
        return response()->json([
            'sequence' => $messageBuffer->append(
                $request->string('phone')->toString(),
                $request->string('content')->toString(),
            ),
            'wait_seconds' => (int) config('condo.conversations.buffer_seconds'),
        ]);
    }

    /**
     * POST /api/v1/conversations/buffer/flush — take the burst if this sequence is still the last one.
     */
    public function flush(BufferFlushRequest $request, MessageBuffer $messageBuffer): JsonResponse
    {
        $content = $messageBuffer->flush(
            $request->string('phone')->toString(),
            $request->integer('sequence'),
        );

        return response()->json([
            'ready' => $content !== null,
            'content' => $content ?? '',
        ]);
    }
}
