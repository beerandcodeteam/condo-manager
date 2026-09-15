<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\TicketNotFound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TicketShowRequest;
use App\Http\Resources\Api\TicketDetailResource;
use App\Services\Integration\ResidentResolver;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/tickets/{protocol} — ticket of the resident's unit with its status history.
 */
class TicketShowController extends Controller
{
    public function __invoke(
        TicketShowRequest $request,
        int $protocol,
        ResidentResolver $residentResolver,
        ToolCallContext $toolCallContext,
    ): JsonResponse {
        $resident = $residentResolver->resolve($request->string('phone')->toString());

        $ticket = $resident->unit->tickets()
            ->where('protocol_number', $protocol)
            ->with([
                'category',
                'priority',
                'status',
                'statusChanges' => fn ($query) => $query->with('toStatus')->oldest()->oldest('id'),
            ])
            ->first() ?? throw new TicketNotFound;

        $toolCallContext->setTicket($ticket->id);

        return response()->json((new TicketDetailResource($ticket))->resolve());
    }
}
