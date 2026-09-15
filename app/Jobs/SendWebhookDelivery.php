<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * POSTs a webhook delivery to the n8n URL, signed with the condominium secret. Non-2xx responses and
 * timeouts are retried with backoff; the delivery is marked `falhou` after the last attempt.
 */
class SendWebhookDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public WebhookDelivery $delivery)
    {
        $this->tries = (int) config('condo.webhooks.tries');
    }

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('condo.webhooks.backoff');
    }

    /**
     * Execute the job.
     *
     * @throws ConnectionException|RuntimeException
     */
    public function handle(): void
    {
        $body = (string) json_encode($this->delivery->payload);
        $signature = hash_hmac('sha256', $body, (string) $this->delivery->condominium->webhook_secret);

        $this->delivery->increment('attempts');

        try {
            $response = Http::timeout((int) config('condo.webhooks.timeout'))
                ->withHeaders(['X-Signature' => "sha256={$signature}"])
                ->withBody($body, 'application/json')
                ->post($this->delivery->url);
        } catch (ConnectionException $exception) {
            $this->delivery->forceFill(['last_response_code' => null, 'last_error' => $exception->getMessage()])->save();

            throw $exception;
        }

        if ($response->successful()) {
            $this->delivery->forceFill([
                'webhook_delivery_status_id' => WebhookDeliveryStatus::idFor(WebhookDeliveryStatus::ENVIADO),
                'last_response_code' => $response->status(),
                'last_error' => null,
                'delivered_at' => now(),
            ])->save();

            return;
        }

        $this->delivery->forceFill(['last_response_code' => $response->status(), 'last_error' => $response->reason()])->save();

        throw new RuntimeException("Webhook delivery #{$this->delivery->id} responded HTTP {$response->status()}.");
    }

    /**
     * All attempts failed: the delivery shows up in "Falhas de webhook".
     */
    public function failed(?Throwable $exception): void
    {
        $this->delivery->forceFill([
            'webhook_delivery_status_id' => WebhookDeliveryStatus::idFor(WebhookDeliveryStatus::FALHOU),
            'failed_at' => now(),
        ])->save();
    }
}
