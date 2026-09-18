<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TenantResolveRequest;
use App\Services\Integration\TenantResolver;
use Illuminate\Http\JsonResponse;

/**
 * Tenant resolution for the n8n flow: exchanges the WhatsApp sender's phone for the condominium
 * tokens it may act with. Authenticated by the platform token, not by a condominium one.
 */
class TenantResolveController extends Controller
{
    /**
     * POST /api/v1/auth/token — condominiums where the phone is an active resident, each with a token.
     */
    public function __invoke(TenantResolveRequest $request, TenantResolver $tenantResolver): JsonResponse
    {
        $matches = $tenantResolver->resolve($request->string('phone')->toString());

        return response()->json([
            'exists' => $matches->isNotEmpty(),
            'matches' => $matches->all(),
        ]);
    }
}
