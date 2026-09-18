<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integration\EchoGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Echo guard of the WhatsApp agent. Authenticated by the platform token because it runs before any
 * condominium is known — the incoming message has to be discarded before the tenant is resolved.
 */
class EchoController extends Controller
{
    /**
     * POST /api/v1/echo — record a message the agent just sent.
     */
    public function store(Request $request, EchoGuard $echoGuard): JsonResponse
    {
        $validated = $request->validate(['message_id' => ['required', 'string', 'max:255']]);

        $echoGuard->remember($validated['message_id']);

        return response()->json(['remembered' => true], 201);
    }

    /**
     * GET /api/v1/echo — whether this incoming message is the agent's own answer coming back.
     */
    public function show(Request $request, EchoGuard $echoGuard): JsonResponse
    {
        $validated = $request->validate(['message_id' => ['required', 'string', 'max:255']]);

        return response()->json(['sent_by_us' => $echoGuard->wasSentByUs($validated['message_id'])]);
    }
}
