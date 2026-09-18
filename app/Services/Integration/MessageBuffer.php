<?php

namespace App\Services\Integration;

use App\Support\PhoneNumber;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Joins the fragments of someone who writes in bursts into one message.
 *
 * Every incoming fragment is appended and gets a sequence number. The n8n flow then waits and asks to
 * flush: only the fragment holding the latest sequence wins and takes the whole burst, so the earlier
 * executions stop without answering.
 */
class MessageBuffer
{
    public function __construct(private CurrentCondominium $currentCondominium) {}

    /**
     * Append a fragment and return its sequence number.
     */
    public function append(string $phone, string $content): int
    {
        return $this->locked($phone, function (string $key) use ($content): int {
            $state = $this->state($key);
            $state['sequence']++;
            $state['fragments'][] = $content;

            // Um remetente em loop não pode fazer o buffer crescer sem limite.
            $state['fragments'] = array_slice($state['fragments'], -(int) config('condo.conversations.buffer_max_fragments'));

            Cache::put($key, $state, (int) config('condo.conversations.buffer_ttl_seconds'));

            return $state['sequence'];
        });
    }

    /**
     * Take the whole burst when this sequence is still the latest, or null when a newer fragment
     * arrived meanwhile — in that case the newer execution is the one that answers.
     */
    public function flush(string $phone, int $sequence): ?string
    {
        return $this->locked($phone, function (string $key) use ($sequence): ?string {
            $state = $this->state($key);

            if ($state['sequence'] !== $sequence || $state['fragments'] === []) {
                return null;
            }

            Cache::forget($key);

            return implode("\n", $state['fragments']);
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(string): TReturn  $callback
     * @return TReturn
     */
    private function locked(string $phone, callable $callback): mixed
    {
        $key = sprintf(
            'agent:buffer:%d:%s',
            $this->currentCondominium->getOrFail()->id,
            PhoneNumber::normalize($phone) ?? $phone,
        );

        /** @var Lock $lock */
        $lock = Cache::lock($key.':lock', 10);

        return $lock->block(5, fn () => $callback($key));
    }

    /**
     * @return array{sequence: int, fragments: list<string>}
     */
    private function state(string $key): array
    {
        /** @var array{sequence: int, fragments: list<string>}|null $state */
        $state = Cache::get($key);

        return $state ?? ['sequence' => 0, 'fragments' => []];
    }
}
