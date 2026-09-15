<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\ResidentNotFound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ResidentLookupRequest;
use App\Http\Resources\Api\ResidentLookupResource;
use App\Services\Integration\ResidentResolver;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/residents/lookup — whether a phone belongs to an active resident of the token's condominium.
 */
class ResidentLookupController extends Controller
{
    public function __invoke(ResidentLookupRequest $request, ResidentResolver $residentResolver): ResidentLookupResource|JsonResponse
    {
        try {
            return new ResidentLookupResource($residentResolver->resolve($request->string('phone')->toString()));
        } catch (ResidentNotFound) {
            return response()->json(['exists' => false]);
        }
    }
}
