<?php

use App\Exceptions\TicketActionBlockedException;
use App\Models\Condominium;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketStatusChange;
use App\Models\WebhookDelivery;
use App\Services\Tickets\TicketService;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Queue::fake();

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->service = app(TicketService::class);
});

test('changes the priority of a non final ticket', function (string $status) {
    $ticket = Ticket::factory()->for($this->condominium)->create(['ticket_status_id' => TicketStatus::idFor($status)]);

    $this->service->changePriority($ticket, TicketPriority::ALTA);

    expect($ticket->fresh())
        ->ticket_priority_id->toBe(TicketPriority::idFor(TicketPriority::ALTA))
        ->ticket_status_id->toBe(TicketStatus::idFor($status));
})->with([TicketStatus::ABERTO, TicketStatus::EM_ANDAMENTO]);

test('a final ticket is blocked', function (string $status) {
    $ticket = Ticket::factory()->for($this->condominium)->create(['ticket_status_id' => TicketStatus::idFor($status)]);

    expect(fn () => $this->service->changePriority($ticket, TicketPriority::ALTA))
        ->toThrow(TicketActionBlockedException::class, 'Chamado finalizado não pode mudar de prioridade.');

    expect($ticket->fresh()->ticket_priority_id)->toBe(TicketPriority::idFor(TicketPriority::MEDIA));
})->with([TicketStatus::RESOLVIDO, TicketStatus::CANCELADO]);

test('an unknown priority is rejected', function () {
    $ticket = Ticket::factory()->aberto()->for($this->condominium)->create();

    $this->service->changePriority($ticket, 'urgente');
})->throws(ValidationException::class);

test('changing the priority records no status change and dispatches no webhook', function () {
    $ticket = Ticket::factory()->aberto()->for($this->condominium)->create();

    $this->service->changePriority($ticket, TicketPriority::BAIXA);
    $this->service->changePriority($ticket, TicketPriority::ALTA);

    expect($ticket->fresh()->ticket_priority_id)->toBe(TicketPriority::idFor(TicketPriority::ALTA))
        ->and(TicketStatusChange::count())->toBe(0)
        ->and(WebhookDelivery::count())->toBe(0);

    Queue::assertNothingPushed();
});
