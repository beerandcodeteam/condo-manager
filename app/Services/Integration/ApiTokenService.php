<?php

namespace App\Services\Integration;

use App\Models\Condominium;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API tokens used by the n8n flow of a condominium. Sanctum stores only the SHA-256 hash.
 */
class ApiTokenService
{
    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function tokens(Condominium $condominium): Collection
    {
        return $condominium->tokens()->latest()->latest('id')->get();
    }

    /**
     * Generate a token whose plain text is available only in the returned object.
     */
    public function generate(Condominium $condominium, string $name): NewAccessToken
    {
        return $condominium->createToken(trim($name));
    }

    /**
     * Revoke (delete) a token of the condominium; requests using it start receiving 401.
     */
    public function revoke(Condominium $condominium, int $tokenId): void
    {
        $condominium->tokens()->whereKey($tokenId)->firstOrFail()->delete();
    }
}
