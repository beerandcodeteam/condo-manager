<?php

namespace App\Services\Integration;

use App\Models\Condominium;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API tokens used by the n8n flow of a condominium. Sanctum stores only the SHA-256 hash.
 */
class ApiTokenService
{
    /**
     * Name prefix of the ephemeral tokens minted per WhatsApp conversation. They are short-lived and
     * numerous, so they stay out of the panel listing.
     */
    public const AGENT_PREFIX = 'n8n:agent:';

    /**
     * Long-lived tokens managed by hand in the panel; the ephemeral agent ones are excluded.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    public function tokens(Condominium $condominium): Collection
    {
        return $condominium->tokens()
            ->where('name', 'not like', self::AGENT_PREFIX.'%')
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * Generate a token whose plain text is available only in the returned object.
     */
    public function generate(Condominium $condominium, string $name): NewAccessToken
    {
        return $condominium->createToken(trim($name));
    }

    /**
     * Mint a short-lived token for one WhatsApp conversation, used by the n8n flow after resolving the
     * tenant from the sender's phone. It expires on its own, so it never needs to be revoked by hand.
     */
    public function generateForAgent(Condominium $condominium, string $phone): NewAccessToken
    {
        return $condominium->createToken(
            self::AGENT_PREFIX.$phone,
            ['*'],
            Carbon::now()->addMinutes((int) config('condo.platform.agent_token_ttl_minutes')),
        );
    }

    /**
     * Revoke (delete) a token of the condominium; requests using it start receiving 401.
     */
    public function revoke(Condominium $condominium, int $tokenId): void
    {
        $condominium->tokens()->whereKey($tokenId)->firstOrFail()->delete();
    }
}
