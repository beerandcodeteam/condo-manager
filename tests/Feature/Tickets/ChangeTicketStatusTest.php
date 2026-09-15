<?php

use App\Exceptions\TicketActionBlockedException;
use App\Jobs\SendWebhookDelivery;
use App\Models\Condominium;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\TicketStatusChange;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Tickets\TicketService;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Queue::fake();

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
    $this->service = app(TicketService::class);
});

test('allowed transitions update the status and record the history', function (string $from, string $to) {
    $ticket = Ticket::factory()->for($this->condominium)->create(['ticket_status_id' => TicketStatus::idFor($from)]);

    $this->service->changeStatus($ticket, $to, 'Técnico agendado para 16/09', $this->sindico);

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor($to));

    $statusChange = TicketStatusChange::sole();

    expect($statusChange)
        ->ticket_id->toBe($ticket->id)
        ->condominium_id->toBe($this->condominium->id)
        ->from_ticket_status_id->toBe(TicketStatus::idFor($from))
        ->to_ticket_status_id->toBe(TicketStatus::idFor($to))
        ->comment->toBe('Técnico agendado para 16/09')
        ->user_id->toBe($this->sindico->id);
})->with([
    'aberto → em_andamento' => [TicketStatus::ABERTO, TicketStatus::EM_ANDAMENTO],
    'aberto → resolvido' => [TicketStatus::ABERTO, TicketStatus::RESOLVIDO],
    'aberto → cancelado' => [TicketStatus::ABERTO, TicketStatus::CANCELADO],
    'em_andamento → resolvido' => [TicketStatus::EM_ANDAMENTO, TicketStatus::RESOLVIDO],
    'em_andamento → cancelado' => [TicketStatus::EM_ANDAMENTO, TicketStatus::CANCELADO],
]);

test('em_andamento → aberto is an invalid transition', function () {
    $ticket = Ticket::factory()->emAndamento()->for($this->condominium)->create();

    expect(fn () => $this->service->changeStatus($ticket, TicketStatus::ABERTO, null, $this->sindico))
        ->toThrow(TicketActionBlockedException::class, 'Transição de status inválida.');

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor(TicketStatus::EM_ANDAMENTO))
        ->and(TicketStatusChange::count())->toBe(0);
});

test('same status and unknown statuses are invalid transitions', function (string $to) {
    $ticket = Ticket::factory()->aberto()->for($this->condominium)->create();

    $this->service->changeStatus($ticket, $to, 'Comentário', $this->sindico);
})->with([TicketStatus::ABERTO, 'reaberto'])->throws(TicketActionBlockedException::class, 'Transição de status inválida.');

test('any transition from a final status is blocked', function (string $final, string $to) {
    $ticket = Ticket::factory()->for($this->condominium)->create(['ticket_status_id' => TicketStatus::idFor($final)]);

    expect(fn () => $this->service->changeStatus($ticket, $to, 'Reabrindo', $this->sindico))
        ->toThrow(TicketActionBlockedException::class, 'Chamado finalizado não pode mudar de status.');

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor($final))
        ->and(TicketStatusChange::count())->toBe(0)
        ->and(WebhookDelivery::count())->toBe(0);
})->with([TicketStatus::RESOLVIDO, TicketStatus::CANCELADO])
    ->with([TicketStatus::ABERTO, TicketStatus::EM_ANDAMENTO, TicketStatus::RESOLVIDO, TicketStatus::CANCELADO]);

test('the status is re-read inside the transaction so a stale model cannot leave a final status', function () {
    $ticket = Ticket::factory()->aberto()->for($this->condominium)->create();
    Ticket::whereKey($ticket->id)->update(['ticket_status_id' => TicketStatus::idFor(TicketStatus::RESOLVIDO)]);

    $this->service->changeStatus($ticket, TicketStatus::CANCELADO, 'Duplicado', $this->sindico);
})->throws(TicketActionBlockedException::class, 'Chamado finalizado não pode mudar de status.');

test('resolvido and cancelado without comment raise a validation error', function (string $to, ?string $comment) {
    $ticket = Ticket::factory()->emAndamento()->for($this->condominium)->create();

    try {
        $this->service->changeStatus($ticket, $to, $comment, $this->sindico);
        $this->fail('ValidationException was not thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('comment');
    }

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor(TicketStatus::EM_ANDAMENTO))
        ->and(TicketStatusChange::count())->toBe(0)
        ->and(WebhookDelivery::count())->toBe(0);
})->with([TicketStatus::RESOLVIDO, TicketStatus::CANCELADO])->with([null, '', '   ']);

test('em_andamento without comment works', function () {
    $ticket = Ticket::factory()->aberto()->for($this->condominium)->create();

    $this->service->changeStatus($ticket, TicketStatus::EM_ANDAMENTO, null, $this->sindico);

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor(TicketStatus::EM_ANDAMENTO))
        ->and(TicketStatusChange::sole()->comment)->toBeNull();
});

test('a ticket with a resident dispatches ticket.status_changed with protocol, status and comment', function () {
    $ticket = Ticket::factory()->aberto()->for($this->condominium)->create();

    $this->service->changeStatus($ticket, TicketStatus::RESOLVIDO, 'Elevador liberado', $this->sindico);

    $delivery = WebhookDelivery::sole();

    expect($delivery->event->slug)->toBe(WebhookEvent::TICKET_STATUS_CHANGED)
        ->and($delivery->subject_type)->toBe($ticket->getMorphClass())
        ->and($delivery->subject_id)->toBe($ticket->id)
        ->and($delivery->resident_phone)->toBe($ticket->resident->phone)
        ->and($delivery->payload['resident_phone'])->toBe($ticket->resident->phone)
        ->and($delivery->payload['data'])->toEqual([
            'protocol' => $ticket->protocol_number,
            'status' => TicketStatus::RESOLVIDO,
            'comment' => 'Elevador liberado',
        ]);

    Queue::assertPushed(SendWebhookDelivery::class, 1);
});

test('a ticket without a resident dispatches no webhook', function () {
    $ticket = Ticket::factory()->fromPanel()->aberto()->for($this->condominium)->create();

    $this->service->changeStatus($ticket, TicketStatus::EM_ANDAMENTO, null, $this->sindico);

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor(TicketStatus::EM_ANDAMENTO))
        ->and(TicketStatusChange::count())->toBe(1)
        ->and(WebhookDelivery::count())->toBe(0);

    Queue::assertNothingPushed();
});

test('no webhook when the condominium has no url, while the change is still recorded', function () {
    $condominium = Condominium::factory()->create();
    $ticket = Ticket::factory()->aberto()->for($condominium)->create();
    $zelador = User::factory()->zelador()->for($condominium)->create();

    $this->service->changeStatus($ticket, TicketStatus::CANCELADO, 'Aberto por engano', $zelador);

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor(TicketStatus::CANCELADO))
        ->and(TicketStatusChange::sole()->user_id)->toBe($zelador->id)
        ->and(WebhookDelivery::count())->toBe(0);

    Queue::assertNothingPushed();
});
