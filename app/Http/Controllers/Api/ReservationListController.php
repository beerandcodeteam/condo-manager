<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReservationListRequest;
use App\Http\Resources\Api\ReservationResource;
use App\Services\Integration\ResidentResolver;
use App\Services\Reservations\ReservationService;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/reservations — confirmed reservations of the resident's unit from today on, with whether the
 * resident can still cancel each one.
 */
class ReservationListController extends Controller
{
    public function __invoke(
        ReservationListRequest $request,
        ResidentResolver $residentResolver,
        ReservationService $reservationService,
        ToolCallContext $toolCallContext,
    ): JsonResponse {
        $resident = $residentResolver->resolve($request->string('phone')->toString());

        $reservations = $reservationService->upcomingOfUnit($resident->unit);

        if ($reservations->isEmpty()) {
            $toolCallContext->markEmpty();
        }

        return response()->json([
            'reservations' => ReservationResource::collection($reservations)->resolve(),
        ]);
    }
}
