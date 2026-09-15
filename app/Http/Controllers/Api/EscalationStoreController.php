<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EscalationStoreRequest;
use App\Models\EscalationStatus;
use App\Services\Escalations\EscalationService;
use App\Services\Integration\ResidentResolver;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/escalations — hands the conversation of the resident behind the phone over to the team.
 */
class EscalationStoreController extends Controller
{
    public function __invoke(
        EscalationStoreRequest $request,
        ResidentResolver $residentResolver,
        EscalationService $escalationService,
        ToolCallContext $toolCallContext,
    ): JsonResponse {
        $resident = $residentResolver->resolve($request->string('phone')->toString());

        $escalation = $escalationService->create(
            resident: $resident,
            reasonSlug: $request->string('reason')->toString(),
            summary: $request->string('summary')->toString(),
            ticketProtocol: $request->filled('ticket_protocol') ? $request->integer('ticket_protocol') : null,
        );

        $toolCallContext->setEscalation($escalation->id);

        return response()->json([
            'id' => $escalation->id,
            'status' => EscalationStatus::PENDENTE,
        ], 201);
    }
}
