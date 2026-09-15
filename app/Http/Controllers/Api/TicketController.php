<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\TicketNotFound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TicketListRequest;
use App\Http\Requests\Api\TicketShowRequest;
use App\Http\Requests\Api\TicketStoreRequest;
use App\Http\Resources\Api\TicketDetailResource;
use App\Http\Resources\Api\TicketResource;
use App\Models\TicketOrigin;
use App\Services\Integration\ResidentResolver;
use App\Services\Tickets\TicketService;
use App\Support\Tenancy\CurrentCondominium;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Tickets of the unit of the resident behind the phone, for the agent tools `abrir_chamado`,
 * `listar_chamados` and `consultar_chamado`.
 */
class TicketController extends Controller
{
    /**
     * Longest protocol that always fits the `tickets.protocol_number` integer column.
     */
    private const MAX_PROTOCOL_DIGITS = 9;

    public function __construct(
        private ResidentResolver $residentResolver,
        private ToolCallContext $toolCallContext,
    ) {}

    /**
     * POST /api/v1/tickets — opens a ticket on behalf of the resident behind the phone, in the resident's unit.
     */
    public function store(
        TicketStoreRequest $request,
        TicketService $ticketService,
        CurrentCondominium $currentCondominium,
    ): JsonResponse {
        $resident = $this->residentResolver->resolve($request->string('phone')->toString());

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

        $this->toolCallContext->setTicket($ticket->id);

        $ticket->load(['status', 'priority']);

        return response()->json([
            'protocol' => $ticket->protocol_number,
            'status' => $ticket->status->slug,
            'priority' => $ticket->priority->slug,
            'created_at' => $ticket->created_at?->copy()->setTimezone(config('condo.timezone'))->toIso8601String(),
        ], 201);
    }

    /**
     * GET /api/v1/tickets — latest tickets of the resident's unit, so the agent can tell which one the resident means.
     */
    public function index(TicketListRequest $request, TicketService $ticketService): JsonResponse
    {
        $resident = $this->residentResolver->resolve($request->string('phone')->toString());

        $tickets = $ticketService->latestOfUnit($resident->unit, $request->input('status') ?? TicketService::API_STATUS_ALL);

        if ($tickets->isEmpty()) {
            $this->toolCallContext->markEmpty();
        }

        return response()->json([
            'tickets' => TicketResource::collection($tickets)->resolve(),
        ]);
    }

    /**
     * GET /api/v1/tickets/{protocol} — ticket of the resident's unit with its status history. The protocol may
     * come as shown to the resident ("#4821"); anything that is not a protocol number responds ticket_not_found.
     */
    public function show(TicketShowRequest $request, string $protocol): JsonResponse
    {
        $resident = $this->residentResolver->resolve($request->string('phone')->toString());

        $protocolNumber = Str::chopStart($protocol, '#');

        if (! ctype_digit($protocolNumber) || strlen($protocolNumber) > self::MAX_PROTOCOL_DIGITS) {
            throw new TicketNotFound;
        }

        $ticket = $resident->unit->tickets()
            ->where('protocol_number', (int) $protocolNumber)
            ->with([
                'category',
                'priority',
                'status',
                'statusChanges' => fn ($query) => $query->with('toStatus')->oldest()->oldest('id'),
            ])
            ->first() ?? throw new TicketNotFound;

        $this->toolCallContext->setTicket($ticket->id);

        return response()->json((new TicketDetailResource($ticket))->resolve());
    }
}
