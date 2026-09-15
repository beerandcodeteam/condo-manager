<?php

use App\Jobs\SendWebhookDelivery;
use App\Models\Condominium;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryStatus;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    Http::preventStrayRequests();

    $this->secret = str_repeat('s', 64);
    $this->condominium = Condominium::factory()->create([
        'webhook_url' => 'https://n8n.example.com/webhook/aurora',
        'webhook_secret' => $this->secret,
    ]);
    $this->delivery = WebhookDelivery::factory()->for($this->condominium)->create([
        'url' => 'https://n8n.example.com/webhook/aurora',
        'payload' => [
            'event' => 'ticket.status_changed',
            'condominium_id' => $this->condominium->id,
            'resident_phone' => '+5541998123344',
            'data' => ['protocol' => 123, 'status' => 'resolvido', 'comment' => 'Elevador liberado'],
            'occurred_at' => '2026-09-15T15:00:00-03:00',
        ],
    ]);
});

test('200 response marks the delivery enviado with a signature matching the hmac of the body', function () {
    Http::fake(['n8n.example.com/*' => Http::response(['ok' => true])]);

    (new SendWebhookDelivery($this->delivery))->handle();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://n8n.example.com/webhook/aurora'
        && $request->method() === 'POST'
        && $request->body() === json_encode($this->delivery->payload)
        && $request->header('Content-Type') === ['application/json']
        && $request->header('X-Signature') === ['sha256='.hash_hmac('sha256', $request->body(), $this->secret)]);

    $delivery = $this->delivery->fresh();

    expect($delivery->webhook_delivery_status_id)->toBe(WebhookDeliveryStatus::idFor(WebhookDeliveryStatus::ENVIADO))
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_response_code)->toBe(200)
        ->and($delivery->last_error)->toBeNull()
        ->and($delivery->delivered_at)->not->toBeNull();
});

test('500 response increments attempts and throws to retry', function () {
    Http::fake(['n8n.example.com/*' => Http::response('Server Error', 500)]);

    expect(fn () => (new SendWebhookDelivery($this->delivery))->handle())->toThrow(RuntimeException::class);

    $delivery = $this->delivery->fresh();

    expect($delivery->attempts)->toBe(1)
        ->and($delivery->last_response_code)->toBe(500)
        ->and($delivery->webhook_delivery_status_id)->toBe(WebhookDeliveryStatus::idFor(WebhookDeliveryStatus::PENDENTE))
        ->and($delivery->delivered_at)->toBeNull();
});

test('failed marks the delivery falhou with failed at keeping the last 500 code', function () {
    Http::fake(['n8n.example.com/*' => Http::response('Server Error', 500)]);

    foreach (range(1, 3) as $attempt) {
        rescue(fn () => (new SendWebhookDelivery($this->delivery->fresh()))->handle(), report: false);
    }

    (new SendWebhookDelivery($this->delivery->fresh()))->failed(new RuntimeException('HTTP 500'));

    $delivery = $this->delivery->fresh();

    expect($delivery->webhook_delivery_status_id)->toBe(WebhookDeliveryStatus::idFor(WebhookDeliveryStatus::FALHOU))
        ->and($delivery->failed_at)->not->toBeNull()
        ->and($delivery->attempts)->toBe(3)
        ->and($delivery->last_response_code)->toBe(500);
});

test('timeout records the last error and throws to retry', function () {
    Http::fake(['n8n.example.com/*' => Http::failedConnection('cURL error 28: Operation timed out after 10000 milliseconds')]);

    expect(fn () => (new SendWebhookDelivery($this->delivery))->handle())->toThrow(ConnectionException::class);

    $delivery = $this->delivery->fresh();

    expect($delivery->attempts)->toBe(1)
        ->and($delivery->last_response_code)->toBeNull()
        ->and($delivery->last_error)->toContain('Operation timed out');
});

test('job is tried 3 times with the configured backoff', function () {
    $job = new SendWebhookDelivery($this->delivery);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe(config('condo.webhooks.backoff'));
});
