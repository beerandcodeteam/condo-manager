<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\ReservationNotFound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReservationCancelRequest;
use App\Models\ReservationStatus;
use App\Services\Integration\ResidentResolver;
use App\Services\Reservations\ReservationService;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;

/**
 * DELETE /api/v1/reservations/{reservation} — cancels a confirmed reservation of the resident's unit before
 * the area cancellation deadline, freeing the slot.
 */
class ReservationCancelController extends Controller
{
    public function __invoke(
        ReservationCancelRequest $request,
        int $reservation,
        ResidentResolver $residentResolver,
        ReservationService $reservationService,
        ToolCallContext $toolCallContext,
    ): JsonResponse {
        $resident = $residentResolver->resolve($request->string('phone')->toString());

        $unitReservation = $resident->unit->reservations()->with('area')->find($reservation)
            ?? throw new ReservationNotFound;

        $toolCallContext->setReservation($unitReservation->id);

        $reservationService->cancelByResident($unitReservation, $resident);

        return response()->json([
            'id' => $unitReservation->id,
            'status' => ReservationStatus::CANCELADA,
        ]);
    }
}
