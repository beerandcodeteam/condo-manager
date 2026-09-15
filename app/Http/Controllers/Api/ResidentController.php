<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\ResidentNotFound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ResidentLookupRequest;
use App\Http\Resources\Api\ResidentLookupResource;
use App\Services\Integration\ResidentResolver;
use Illuminate\Http\JsonResponse;

/**
 * Residents of the token's condominium, for the agent tool `verificar_morador`.
 */
class ResidentController extends Controller
{
    /**
     * GET /api/v1/residents/lookup — whether a phone belongs to an active resident of the token's condominium.
     */
    public function lookup(ResidentLookupRequest $request, ResidentResolver $residentResolver): ResidentLookupResource|JsonResponse
    {
        try {
            return new ResidentLookupResource($residentResolver->resolve($request->string('phone')->toString()));
        } catch (ResidentNotFound) {
            return response()->json(['exists' => false]);
        }
    }
}
