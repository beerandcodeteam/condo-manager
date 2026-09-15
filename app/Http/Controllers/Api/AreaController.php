<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\AreaNotFound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AreaAvailabilityRequest;
use App\Http\Resources\Api\CommonAreaResource;
use App\Services\Reservations\ReservationService;
use App\Support\Tenancy\CurrentCondominium;
use App\Support\ToolCallContext;
use Illuminate\Http\JsonResponse;

/**
 * Common areas of the token's condominium, for the agent tools `listar_areas` and `consultar_disponibilidade`.
 */
class AreaController extends Controller
{
    /**
     * Longest id that always fits the `common_areas.id` bigint column.
     */
    private const MAX_ID_DIGITS = 18;

    public function __construct(private CurrentCondominium $currentCondominium) {}

    /**
     * GET /api/v1/areas — active common areas of the token's condominium with their slots and advance rules.
     */
    public function index(ToolCallContext $toolCallContext): JsonResponse
    {
        $areas = $this->currentCondominium->getOrFail()
            ->commonAreas()
            ->active()
            ->with('slots')
            ->orderBy('name')
            ->get();

        if ($areas->isEmpty()) {
            $toolCallContext->markEmpty();
        }

        return response()->json([
            'areas' => CommonAreaResource::collection($areas)->resolve(),
        ]);
    }

    /**
     * GET /api/v1/areas/{area}/availability — each slot of an active area on the date, with `available` false
     * when it is already reserved or outside the advance window.
     */
    public function availability(
        AreaAvailabilityRequest $request,
        string $area,
        ReservationService $reservationService,
    ): JsonResponse {
        $commonArea = ctype_digit($area) && strlen($area) <= self::MAX_ID_DIGITS
            ? $this->currentCondominium->getOrFail()->commonAreas()->active()->find((int) $area)
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
