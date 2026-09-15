<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TicketListRequest;
use App\Http\Resources\Api\TicketResource;
use App\Services\Integration\ResidentResolver;
use App\Services\Tickets\TicketService;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/tickets — latest tickets of the resident's unit, so the agent can tell which one the resident means.
 */
class TicketListController extends Controller
{
    public function __invoke(
        TicketListRequest $request,
        ResidentResolver $residentResolver,
        TicketService $ticketService,
        ToolCallContext $toolCallContext,
    ): JsonResponse {
        $resident = $residentResolver->resolve($request->string('phone')->toString());

        $tickets = $ticketService->latestOfUnit($resident->unit, $request->input('status') ?? TicketService::API_STATUS_ALL);

        if ($tickets->isEmpty()) {
            $toolCallContext->markEmpty();
        }

        return response()->json([
            'tickets' => TicketResource::collection($tickets)->resolve(),
        ]);
    }
}
