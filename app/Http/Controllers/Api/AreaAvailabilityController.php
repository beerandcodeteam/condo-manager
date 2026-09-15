<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\AreaNotFound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AreaAvailabilityRequest;
use App\Services\Reservations\ReservationService;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/areas/{area}/availability — each slot of an active area on the date, with `available` false
 * when it is already reserved or outside the advance window.
 */
class AreaAvailabilityController extends Controller
{
    public function __invoke(
        AreaAvailabilityRequest $request,
        string $area,
        CurrentCondominium $currentCondominium,
        ReservationService $reservationService,
    ): JsonResponse {
        $commonArea = ctype_digit($area) && strlen($area) <= 18
            ? $currentCondominium->getOrFail()->commonAreas()->active()->find((int) $area)
            : null;

        if ($commonArea === null) {
            throw new AreaNotFound;
        }

        $date = $request->string('date')->toString();

        return response()->json([
            'area' => ['id' => $commonArea->id, 'name' => $commonArea->name],
            'date' => $date,
            'slots' => array_map(fn (array $slot): array => [
                'id' => $slot['id'],
                'starts' => $slot['starts'],
                'ends' => $slot['ends'],
                'available' => $slot['available'],
            ], $reservationService->availability($commonArea, $date)),
        ]);
    }
}
