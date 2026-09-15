<?php

use App\Exceptions\TicketActionBlockedException;
use App\Jobs\SendWebhookDelivery;
use App\Models\Condominium;
use App\Models\Ticket;
use App\Models\TicketResidentNotice;
use App\Models\TicketStatusChange;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Tickets\TicketService;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Queue::fake();

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
    $this->service = app(TicketService::class);
});

test('creates the notice linked to the webhook delivery with protocol and message', function () {
    $ticket = Ticket::factory()->emAndamento()->for($this->condominium)->create();

    $notice = $this->service->notifyResident($ticket, '  Visita técnica agendada para amanhã às 9h.  ', $this->sindico);

    $delivery = WebhookDelivery::sole();

    expect($notice->fresh())
        ->toBeInstanceOf(TicketResidentNotice::class)
        ->condominium_id->toBe($this->condominium->id)
        ->ticket_id->toBe($ticket->id)
        ->user_id->toBe($this->sindico->id)
        ->message->toBe('Visita técnica agendada para amanhã às 9h.')
        ->webhook_delivery_id->toBe($delivery->id);

    expect($delivery->event->slug)->toBe(WebhookEvent::TICKET_RESIDENT_NOTIFIED)
        ->and($delivery->subject_id)->toBe($ticket->id)
        ->and($delivery->resident_phone)->toBe($ticket->resident->phone)
        ->and($delivery->payload['data'])->toEqual([
            'protocol' => $ticket->protocol_number,
            'message' => 'Visita técnica agendada para amanhã às 9h.',
        ])
        ->and(TicketStatusChange::count())->toBe(0);

    Queue::assertPushed(SendWebhookDelivery::class, 1);
});

test('a ticket without a resident is blocked', function () {
    $ticket = Ticket::factory()->fromPanel()->for($this->condominium)->create();

    expect(fn () => $this->service->notifyResident($ticket, 'Olá', $this->sindico))
        ->toThrow(TicketActionBlockedException::class, 'Chamado sem morador vinculado não pode receber aviso.');

    expect(TicketResidentNotice::count())->toBe(0)
        ->and(WebhookDelivery::count())->toBe(0);
});

test('empty and 1001 character messages raise a validation error', function (string $message) {
    $ticket = Ticket::factory()->for($this->condominium)->create();

    try {
        $this->service->notifyResident($ticket, $message, $this->sindico);
        $this->fail('ValidationException was not thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('message');
    }

    expect(TicketResidentNotice::count())->toBe(0)
        ->and(WebhookDelivery::count())->toBe(0);
})->with([
    'empty' => '',
    'blank' => '    ',
    '1001 characters' => Str::repeat('a', 1001),
]);

test('a 1000 character message is accepted', function () {
    $ticket = Ticket::factory()->for($this->condominium)->create();

    $notice = $this->service->notifyResident($ticket, Str::repeat('a', 1000), $this->sindico);

    expect(mb_strlen($notice->message))->toBe(1000);
});

test('a final ticket accepts the notice', function (string $state) {
    $ticket = Ticket::factory()->{$state}()->for($this->condominium)->create();

    $this->service->notifyResident($ticket, 'O reparo foi concluído.', $this->sindico);

    expect(TicketResidentNotice::count())->toBe(1)
        ->and(WebhookDelivery::count())->toBe(1);
})->with(['resolvido', 'cancelado']);

test('without webhook the notice is recorded with a null webhook_delivery_id', function () {
    $condominium = Condominium::factory()->create();
    $ticket = Ticket::factory()->for($condominium)->create();
    $zelador = User::factory()->zelador()->for($condominium)->create();

    $notice = $this->service->notifyResident($ticket, 'Visita agendada.', $zelador);

    expect($notice->fresh())
        ->webhook_delivery_id->toBeNull()
        ->user_id->toBe($zelador->id)
        ->and(WebhookDelivery::count())->toBe(0);

    Queue::assertNothingPushed();
});
