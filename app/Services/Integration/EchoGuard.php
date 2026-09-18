<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers the WhatsApp message ids the agent itself sent.
 *
 * The flow may accept messages marked `fromMe` so a single phone can be used for testing. Without
 * this the agent would read back its own answer as a new question and loop forever.
 */
class EchoGuard
{
    /**
     * Record that the agent sent this message.
     */
    public function remember(string $messageId): void
    {
        Cache::put($this->key($messageId), true, (int) config('condo.conversations.echo_ttl_seconds'));
    }

    /**
     * Whether this incoming message is the agent's own answer coming back.
     */
    public function wasSentByUs(string $messageId): bool
    {
        return Cache::get($this->key($messageId), false) === true;
    }

    private function key(string $messageId): string
    {
        return 'agent:sent:'.sha1($messageId);
    }
}
