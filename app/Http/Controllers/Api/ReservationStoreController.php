<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\AreaUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReservationStoreRequest;
use App\Models\CommonAreaSlot;
use App\Models\ReservationOrigin;
use App\Models\ReservationStatus;
use App\Services\Integration\ResidentResolver;
use App\Services\Reservations\ReservationService;
use App\Support\Tenancy\CurrentCondominium;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/reservations — reserves a slot for the resident behind the phone; the reservation is born
 * `confirmada` only when every rule passes, otherwise nothing is created.
 */
class ReservationStoreController extends Controller
{
    public function __invoke(
        ReservationStoreRequest $request,
        ResidentResolver $residentResolver,
        ReservationService $reservationService,
        CurrentCondominium $currentCondominium,
        ToolCallContext $toolCallContext,
    ): JsonResponse {
        $resident = $residentResolver->resolve($request->string('phone')->toString());
        $condominium = $currentCondominium->getOrFail();

        $area = $condominium->commonAreas()->find($request->integer('area_id'));
        $slot = CommonAreaSlot::query()->withTrashed()->where('condominium_id', $condominium->id)->find($request->integer('slot_id'));

        if ($area === null || $slot === null) {
            throw new AreaUnavailable;
        }

        $reservation = $reservationService->create(
            area: $area,
            slot: $slot,
            date: $request->string('date')->toString(),
            resident: $resident,
            origin: ReservationOrigin::WHATSAPP,
        );

        $toolCallContext->setReservation($reservation->id);

        return response()->json([
            'id' => $reservation->id,
            'status' => ReservationStatus::CONFIRMADA,
            'area' => $area->name,
            'date' => $reservation->date->format('Y-m-d'),
            'starts' => $reservation->starts,
            'ends' => $reservation->ends,
        ], 201);
    }
}
