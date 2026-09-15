<?php

use App\Jobs\SendWebhookDelivery;
use App\Models\Condominium;
use App\Models\Ticket;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryStatus;
use App\Models\WebhookEvent;
use App\Services\Integration\WebhookService;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    Queue::fake();
});

test('condominium without url creates no delivery and queues no job', function () {
    $ticket = Ticket::factory()->for(Condominium::factory())->create();

    $delivery = app(WebhookService::class)->dispatch(WebhookEvent::TICKET_STATUS_CHANGED, $ticket, '+5541998123344', ['protocol' => 1]);

    expect($delivery)->toBeNull()
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('condominium with url creates a pending delivery with the payload and queues the job', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 18:00:00', 'UTC'));

    $condominium = Condominium::factory()->create(['webhook_url' => 'https://n8n.example.com/webhook/aurora']);
    $ticket = Ticket::factory()->for($condominium)->create();
    $data = ['protocol' => 123, 'status' => 'resolvido', 'comment' => 'Elevador liberado'];

    $delivery = app(WebhookService::class)->dispatch(WebhookEvent::TICKET_STATUS_CHANGED, $ticket, '+5541998123344', $data)->fresh();

    expect($delivery->condominium_id)->toBe($condominium->id)
        ->and($delivery->webhook_event_id)->toBe(WebhookEvent::idFor(WebhookEvent::TICKET_STATUS_CHANGED))
        ->and($delivery->webhook_delivery_status_id)->toBe(WebhookDeliveryStatus::idFor(WebhookDeliveryStatus::PENDENTE))
        ->and($delivery->subject->is($ticket))->toBeTrue()
        ->and($delivery->resident_phone)->toBe('+5541998123344')
        ->and($delivery->url)->toBe('https://n8n.example.com/webhook/aurora')
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->payload)->toEqual([
            'event' => 'ticket.status_changed',
            'condominium_id' => $condominium->id,
            'resident_phone' => '+5541998123344',
            'data' => $data,
            'occurred_at' => '2026-09-15T15:00:00-03:00',
        ]);

    Queue::assertPushed(SendWebhookDelivery::class, fn (SendWebhookDelivery $job) => $job->delivery->is($delivery));
});

test('dispatch inside a rolled back transaction queues no job', function () {
    $condominium = Condominium::factory()->withWebhook()->create();
    $ticket = Ticket::factory()->for($condominium)->create();

    try {
        DB::transaction(function () use ($ticket) {
            app(WebhookService::class)->dispatch(WebhookEvent::TICKET_STATUS_CHANGED, $ticket, null, []);

            throw new RuntimeException('Ação revertida');
        });
    } catch (RuntimeException) {
        //
    }

    Queue::assertNothingPushed();
    expect(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a failing send does not break the action that dispatched the event', function () {
    Queue::fake()->except(SendWebhookDelivery::class);
    config(['queue.default' => 'sync']);
    Http::fake(['*' => Http::response('Server Error', 500)]);

    $condominium = Condominium::factory()->withWebhook()->create();
    $ticket = Ticket::factory()->for($condominium)->create();

    $delivery = DB::transaction(fn () => app(WebhookService::class)->dispatch(WebhookEvent::TICKET_STATUS_CHANGED, $ticket, null, []));

    expect($delivery->fresh()->last_response_code)->toBe(500);
});
