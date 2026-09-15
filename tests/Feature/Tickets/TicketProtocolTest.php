<?php

use App\Models\Condominium;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\TicketOrigin;
use App\Models\User;
use App\Services\Tickets\TicketService;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominiumA = Condominium::factory()->create();
    $this->condominiumB = Condominium::factory()->create();
    $this->service = app(TicketService::class);
});

test('three openings in A generate protocols 1, 2 and 3', function () {
    $protocols = collect(range(1, 3))->map(fn () => $this->service->open(
        condominium: $this->condominiumA,
        description: 'Lâmpada queimada na garagem',
        origin: TicketOrigin::PAINEL,
    )->protocol_number);

    expect($protocols->all())->toBe([1, 2, 3])
        ->and($this->condominiumA->fresh()->last_ticket_protocol)->toBe(3);
});

test('the sequence is independent per condominium and the first opening in B generates 1', function () {
    $this->service->open(condominium: $this->condominiumA, description: 'Portão travado', origin: TicketOrigin::PAINEL);
    $this->service->open(condominium: $this->condominiumA, description: 'Interfone mudo', origin: TicketOrigin::PAINEL);

    $ticketInB = $this->service->open(condominium: $this->condominiumB, description: 'Vazamento no hall', origin: TicketOrigin::PAINEL);

    expect($ticketInB->protocol_number)->toBe(1)
        ->and($this->condominiumA->fresh()->last_ticket_protocol)->toBe(2)
        ->and($this->condominiumB->fresh()->last_ticket_protocol)->toBe(1);
});

test('next protocol never restarts, even after tickets are removed', function () {
    $ticket = $this->service->open(condominium: $this->condominiumA, description: 'Portão travado', origin: TicketOrigin::PAINEL);
    $ticket->statusChanges()->delete();
    $ticket->delete();

    expect($this->service->nextProtocol($this->condominiumA))->toBe(2)
        ->and($this->service->nextProtocol($this->condominiumA))->toBe(3);
});

test('openings from the panel and from the api share the same sequence', function () {
    $resident = Resident::factory()->for($this->condominiumA)->create();
    $sindico = User::factory()->sindico()->for($this->condominiumA)->create();

    $fromApi = $this->service->open(
        condominium: $this->condominiumA,
        description: 'Elevador parado',
        origin: TicketOrigin::WHATSAPP,
        resident: $resident,
    );

    $fromPanel = $this->service->open(
        condominium: $this->condominiumA,
        description: 'Lâmpada queimada',
        origin: TicketOrigin::PAINEL,
        user: $sindico,
    );

    $fromApiAgain = $this->service->open(
        condominium: $this->condominiumA,
        description: 'Infiltração',
        origin: TicketOrigin::WHATSAPP,
        resident: $resident,
    );

    expect([$fromApi->protocol_number, $fromPanel->protocol_number, $fromApiAgain->protocol_number])->toBe([1, 2, 3]);
});

test('a forced duplicate protocol number is rejected by the database', function () {
    $ticket = $this->service->open(condominium: $this->condominiumA, description: 'Portão travado', origin: TicketOrigin::PAINEL);

    Ticket::factory()->fromPanel()->for($this->condominiumA)->create(['protocol_number' => $ticket->protocol_number]);
})->throws(UniqueConstraintViolationException::class);

test('the same protocol number may exist in another condominium', function () {
    $this->service->open(condominium: $this->condominiumA, description: 'Portão travado', origin: TicketOrigin::PAINEL);

    $ticket = Ticket::factory()->fromPanel()->for($this->condominiumB)->create(['protocol_number' => 1]);

    expect($ticket->exists)->toBeTrue();
});
