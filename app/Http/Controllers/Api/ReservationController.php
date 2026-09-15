<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\AreaUnavailable;
use App\Exceptions\Api\ReservationNotFound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReservationCancelRequest;
use App\Http\Requests\Api\ReservationListRequest;
use App\Http\Requests\Api\ReservationStoreRequest;
use App\Http\Resources\Api\ReservationResource;
use App\Models\CommonAreaSlot;
use App\Models\ReservationOrigin;
use App\Models\ReservationStatus;
use App\Services\Integration\ResidentResolver;
use App\Services\Reservations\ReservationService;
use App\Support\Tenancy\CurrentCondominium;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;

/**
 * Reservations of the unit of the resident behind the phone, for the agent tools `reservar_area`,
 * `listar_reservas` and `cancelar_reserva`.
 */
class ReservationController extends Controller
{
    /**
     * Longest id that always fits the `reservations.id` bigint column.
     */
    private const MAX_ID_DIGITS = 18;

    public function __construct(
        private ResidentResolver $residentResolver,
        private ReservationService $reservationService,
        private ToolCallContext $toolCallContext,
    ) {}

    /**
     * POST /api/v1/reservations — reserves a slot for the resident behind the phone; the reservation is born
     * `confirmada` only when every rule passes, otherwise nothing is created.
     */
    public function store(ReservationStoreRequest $request, CurrentCondominium $currentCondominium): JsonResponse
    {
        $resident = $this->residentResolver->resolve($request->string('phone')->toString());
        $condominium = $currentCondominium->getOrFail();

        $area = $condominium->commonAreas()->find($request->integer('area_id'));
        $slot = CommonAreaSlot::query()->withTrashed()->where('condominium_id', $condominium->id)->find($request->integer('slot_id'));

        if ($area === null || $slot === null) {
            throw new AreaUnavailable;
        }

        $reservation = $this->reservationService->create(
            area: $area,
            slot: $slot,
            date: $request->string('date')->toString(),
            resident: $resident,
            origin: ReservationOrigin::WHATSAPP,
        );

        $this->toolCallContext->setReservation($reservation->id);

        return response()->json([
            'id' => $reservation->id,
            'status' => ReservationStatus::CONFIRMADA,
            'area' => $area->name,
            'date' => $reservation->date->format('Y-m-d'),
            'starts' => $reservation->starts,
            'ends' => $reservation->ends,
        ], 201);
    }

    /**
     * GET /api/v1/reservations — confirmed reservations of the resident's unit from today on, with whether the
     * resident can still cancel each one.
     */
    public function index(ReservationListRequest $request): JsonResponse
    {
        $resident = $this->residentResolver->resolve($request->string('phone')->toString());

        $reservations = $this->reservationService->upcomingOfUnit($resident->unit);

        if ($reservations->isEmpty()) {
            $this->toolCallContext->markEmpty();
        }

        return response()->json([
            'reservations' => ReservationResource::collection($reservations)->resolve(),
        ]);
    }

    /**
     * DELETE /api/v1/reservations/{reservation} — cancels a confirmed reservation of the resident's unit before
     * the area cancellation deadline, freeing the slot. A non-numeric id responds reservation_not_found.
     */
    public function destroy(ReservationCancelRequest $request, string $reservation): JsonResponse
    {
        $resident = $this->residentResolver->resolve($request->string('phone')->toString());

        $unitReservation = ctype_digit($reservation) && strlen($reservation) <= self::MAX_ID_DIGITS
            ? $resident->unit->reservations()->with('area')->find((int) $reservation)
            : null;

        if ($unitReservation === null) {
            throw new ReservationNotFound;
        }

        $this->toolCallContext->setReservation($unitReservation->id);

        $this->reservationService->cancelByResident($unitReservation, $resident);

        return response()->json([
            'id' => $unitReservation->id,
            'status' => ReservationStatus::CANCELADA,
        ]);
    }
}
