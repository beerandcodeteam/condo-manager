<?php

namespace App\Services\Integration;

use App\Models\Condominium;
use Illuminate\Support\Str;

/**
 * n8n webhook of a condominium: destination URL and the HMAC signing secret (stored encrypted).
 */
class WebhookService
{
    public const SECRET_LENGTH = 64;

    /**
     * URL schemes accepted for the webhook: https only, plus http in the local environment.
     *
     * @return list<string>
     */
    public function allowedSchemes(): array
    {
        return app()->environment('local') ? ['http', 'https'] : ['https'];
    }

    public function updateUrl(Condominium $condominium, ?string $url): Condominium
    {
        $condominium->update(['webhook_url' => filled($url) ? trim($url) : null]);

        return $condominium;
    }

    /**
     * Replace the signing secret with a new random one, returning it in plain text to be shown only once.
     */
    public function regenerateSecret(Condominium $condominium): string
    {
        $secret = Str::random(self::SECRET_LENGTH);

        $condominium->update(['webhook_secret' => $secret]);

        return $secret;
    }
}
