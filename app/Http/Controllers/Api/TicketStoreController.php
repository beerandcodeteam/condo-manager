<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TicketStoreRequest;
use App\Models\TicketOrigin;
use App\Services\Integration\ResidentResolver;
use App\Services\Tickets\TicketService;
use App\Support\Tenancy\CurrentCondominium;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/**
 * POST /api/v1/tickets — opens a ticket on behalf of the resident behind the phone, in the resident's unit.
 */
class TicketStoreController extends Controller
{
    public function __invoke(
        TicketStoreRequest $request,
        ResidentResolver $residentResolver,
        TicketService $ticketService,
        CurrentCondominium $currentCondominium,
        ToolCallContext $toolCallContext,
    ): JsonResponse {
        $resident = $residentResolver->resolve($request->string('phone')->toString());

        /** @var list<UploadedFile> $photos */
        $photos = array_values($request->file('photos', []));

        $ticket = $ticketService->open(
            condominium: $currentCondominium->getOrFail(),
            description: $request->string('description')->toString(),
            origin: TicketOrigin::WHATSAPP,
            location: $request->input('location'),
            category: $request->input('category'),
            priority: $request->input('priority'),
            unit: $resident->unit,
            resident: $resident,
            photos: $photos,
        );

        $toolCallContext->setTicket($ticket->id);

        $ticket->load(['status', 'priority']);

        return response()->json([
            'protocol' => $ticket->protocol_number,
            'status' => $ticket->status->slug,
            'priority' => $ticket->priority->slug,
            'created_at' => $ticket->created_at?->copy()->setTimezone(config('condo.timezone'))->toIso8601String(),
        ], 201);
    }
}
