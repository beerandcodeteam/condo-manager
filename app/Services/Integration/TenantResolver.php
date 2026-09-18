<?php

namespace App\Services\Integration;

use App\Models\Resident;
use App\Support\PhoneNumber;
use App\Support\Tenancy\CondominiumScope;
use Illuminate\Support\Collection;

/**
 * Finds which condominiums a WhatsApp sender belongs to, before any tenant is known, and mints a
 * short-lived token for each one. This is the only place that queries residents across tenants.
 */
class TenantResolver
{
    public function __construct(private ApiTokenService $apiTokenService) {}

    /**
     * Active residents with this phone, one entry per condominium. The same phone may belong to
     * residents of different condominiums, so the caller has to disambiguate when there is more than one.
     *
     * @return Collection<int, array{condominium: array{id: int, name: string}, resident: array{id: int, name: string, phone: string}, unit: array{id: int, number: string, block: string|null}, token: string, expires_at: string}>
     */
    public function resolve(string $phone): Collection
    {
        $normalizedPhone = PhoneNumber::normalize($phone);

        if ($normalizedPhone === null) {
            return collect();
        }

        return Resident::query()
            ->withoutGlobalScope(CondominiumScope::class)
            ->where('phone', $normalizedPhone)
            ->active()
            ->with(['condominium', 'unit.block'])
            ->get()
            ->map(function (Resident $resident): array {
                $token = $this->apiTokenService->generateForAgent($resident->condominium, $resident->phone);

                return [
                    'condominium' => [
                        'id' => $resident->condominium->id,
                        'name' => $resident->condominium->name,
                    ],
                    'resident' => [
                        'id' => $resident->id,
                        'name' => $resident->name,
                        'phone' => $resident->phone,
                    ],
                    'unit' => [
                        'id' => $resident->unit->id,
                        'number' => $resident->unit->number,
                        'block' => $resident->unit->block?->name,
                    ],
                    'token' => $token->plainTextToken,
                    'expires_at' => $token->accessToken->expires_at->toIso8601String(),
                ];
            });
    }
}
