<?php

namespace App\Services\Integration;

use App\Jobs\SendWebhookDelivery;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryStatus;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * n8n webhook of a condominium: destination URL, the HMAC signing secret (stored encrypted) and event dispatching.
 */
class WebhookService
{
    public const SECRET_LENGTH = 64;

    public const FAILURES_PER_PAGE = 10;

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

    /**
     * Failed deliveries of the condominium, most recent first, with what is needed to describe them.
     *
     * @return LengthAwarePaginator<int, WebhookDelivery>
     */
    public function failures(Condominium $condominium): LengthAwarePaginator
    {
        $failures = $condominium->webhookDeliveries()
            ->failed()
            ->with(['event', 'subject'])
            ->latest('failed_at')
            ->latest('id')
            ->paginate(self::FAILURES_PER_PAGE);

        $failures->getCollection()->loadMorph('subject', [Reservation::class => ['area']]);

        return $failures;
    }

    /**
     * Record a pending delivery of the event about the subject (ticket, reservation or escalation) and queue it
     * once the surrounding transaction commits. Returns null, queuing nothing, when the condominium has no webhook URL.
     * Sending failures are only reported: they never undo or block the action that originated the event.
     *
     * @param  array<string, mixed>  $data
     */
    public function dispatch(string $eventSlug, Model $subject, ?string $residentPhone, array $data): ?WebhookDelivery
    {
        $condominium = Condominium::query()->whereKey($subject->getAttribute('condominium_id'))->firstOrFail();

        if (! $condominium->hasWebhook()) {
            return null;
        }

        $delivery = new WebhookDelivery([
            'webhook_event_id' => WebhookEvent::idFor($eventSlug),
            'webhook_delivery_status_id' => WebhookDeliveryStatus::idFor(WebhookDeliveryStatus::PENDENTE),
            'resident_phone' => $residentPhone,
            'url' => $condominium->webhook_url,
            'payload' => [
                'event' => $eventSlug,
                'condominium_id' => $condominium->id,
                'resident_phone' => $residentPhone,
                'data' => $data,
                'occurred_at' => now()->setTimezone(config('condo.timezone'))->toIso8601String(),
            ],
            'attempts' => 0,
        ]);

        $delivery->condominium_id = $condominium->id;
        $delivery->subject()->associate($subject);
        $delivery->save();

        DB::afterCommit(function () use ($delivery): void {
            rescue(function () use ($delivery): void {
                SendWebhookDelivery::dispatch($delivery);
            });
        });

        return $delivery;
    }
}
