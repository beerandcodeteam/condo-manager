<?php

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\TicketResidentNotice;
use App\Models\TicketStatus;
use App\Models\TicketStatusChange;
use App\Models\ToolCallResult;
use App\Models\Unit;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;
    $this->unit = Unit::factory()->for($this->condominium)->create();
    $this->resident = Resident::factory()->for($this->unit)->create(['phone' => '+5511999990000']);
});

test('lists only the tickets of the resident unit with the item fields', function () {
    $category = TicketCategory::factory()->for($this->condominium)->create(['name' => 'Hidráulica', 'slug' => 'hidraulica']);
    $roommate = Resident::factory()->for($this->unit)->create();

    $this->travelTo('2026-09-10 11:00:00');
    $ticket = Ticket::factory()->emAndamento()->highPriority()->for($roommate)->create([
        'description' => 'Infiltração no teto do banheiro',
        'ticket_category_id' => $category->id,
    ]);
    $this->travelTo('2026-09-14 19:40:00');
    $ticket->touch();

    Ticket::factory()->for(Unit::factory()->for($this->condominium))->create(['description' => 'Outra unidade']);
    Ticket::factory()->for(Condominium::factory())->create(['description' => 'Outro condomínio']);

    $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_list', ['phone' => '+55 11 99999-0000']))
        ->assertOk()
        ->assertExactJson([
            'tickets' => [[
                'protocol' => $ticket->protocol_number,
                'description' => 'Infiltração no teto do banheiro',
                'category' => 'hidraulica',
                'priority' => 'alta',
                'status' => 'em_andamento',
                'created_at' => '2026-09-10T08:00:00-03:00',
                'updated_at' => '2026-09-14T16:40:00-03:00',
            ]],
        ]);
});

test('status filters open, closed and all with all as default and invalid values responding 422', function () {
    $aberto = Ticket::factory()->aberto()->for($this->resident)->create(['created_at' => now()->subDays(4)]);
    $emAndamento = Ticket::factory()->emAndamento()->for($this->resident)->create(['created_at' => now()->subDays(3)]);
    $resolvido = Ticket::factory()->resolvido()->for($this->resident)->create(['created_at' => now()->subDays(2)]);
    $cancelado = Ticket::factory()->cancelado()->fromPanel()->create([
        'condominium_id' => $this->condominium->id,
        'unit_id' => $this->unit->id,
        'created_at' => now()->subDay(),
    ]);

    $protocols = fn (?string $status) => $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_list', array_filter(['phone' => '+5511999990000', 'status' => $status])))
        ->assertOk()
        ->json('tickets.*.protocol');

    expect($protocols('open'))->toBe([$emAndamento->protocol_number, $aberto->protocol_number])
        ->and($protocols('closed'))->toBe([$cancelado->protocol_number, $resolvido->protocol_number])
        ->and($protocols('all'))->toBe([$cancelado->protocol_number, $resolvido->protocol_number, $emAndamento->protocol_number, $aberto->protocol_number])
        ->and($protocols(null))->toBe($protocols('all'));

    $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_list', ['phone' => '+5511999990000', 'status' => 'pending']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonValidationErrors(['status' => 'O status deve ser open, closed, all.']);
});

test('the list returns at most 10 tickets, most recent first', function () {
    $tickets = collect(range(1, 12))->map(fn (int $day) => Ticket::factory()->for($this->resident)->create([
        'created_at' => now()->subDays(20 - $day),
    ]));

    $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_list', ['phone' => '+5511999990000']))
        ->assertOk()
        ->assertJsonCount(10, 'tickets')
        ->assertJsonPath('tickets.*.protocol', $tickets->reverse()->take(10)->pluck('protocol_number')->values()->all());
});

test('an empty list logs vazio and a list with items logs sucesso', function () {
    $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_list', ['phone' => '+5511999990000']))
        ->assertOk()
        ->assertExactJson(['tickets' => []]);

    Ticket::factory()->for($this->resident)->create();

    $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_list', ['phone' => '+5511999990000']))
        ->assertOk()
        ->assertJsonCount(1, 'tickets');

    $toolCalls = AgentToolCall::query()->withoutGlobalScopes()->orderBy('id')->get();

    expect($toolCalls->pluck('agent_tool_id')->unique()->all())->toBe([AgentTool::idFor(AgentTool::TICKETS_LIST)])
        ->and($toolCalls[0]->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::VAZIO))
        ->and($toolCalls[0]->resident_id)->toBe($this->resident->id)
        ->and($toolCalls[1]->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO));
});

test('detail includes the chronological status history only', function () {
    $this->travelTo('2026-09-10 11:00:00');
    $ticket = Ticket::factory()->for($this->resident)->create([
        'description' => 'Infiltração no teto do banheiro',
        'ticket_category_id' => null,
    ]);

    TicketStatusChange::factory()->for($ticket)->create(['created_at' => '2026-09-14 19:40:00', 'from_ticket_status_id' => TicketStatus::idFor(TicketStatus::ABERTO), 'to_ticket_status_id' => TicketStatus::idFor(TicketStatus::EM_ANDAMENTO), 'comment' => 'Técnico agendado para 16/09']);
    TicketStatusChange::factory()->for($ticket)->create(['created_at' => '2026-09-10 11:00:00']);
    TicketResidentNotice::factory()->for($ticket)->create(['created_at' => '2026-09-12 12:00:00', 'message' => 'Visita confirmada']);

    $ticket->update(['ticket_status_id' => TicketStatus::idFor(TicketStatus::EM_ANDAMENTO), 'ticket_priority_id' => TicketPriority::idFor(TicketPriority::MEDIA)]);

    $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_show', ['protocol' => $ticket->protocol_number, 'phone' => '+5511999990000']))
        ->assertOk()
        ->assertExactJson([
            'protocol' => $ticket->protocol_number,
            'description' => 'Infiltração no teto do banheiro',
            'category' => null,
            'priority' => 'media',
            'status' => 'em_andamento',
            'created_at' => '2026-09-10T08:00:00-03:00',
            'updated_at' => '2026-09-10T08:00:00-03:00',
            'history' => [
                ['status' => 'aberto', 'at' => '2026-09-10T08:00:00-03:00', 'comment' => null],
                ['status' => 'em_andamento', 'at' => '2026-09-14T16:40:00-03:00', 'comment' => 'Técnico agendado para 16/09'],
            ],
        ]);

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->agent_tool_id)->toBe(AgentTool::idFor(AgentTool::TICKETS_SHOW))
        ->and($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO))
        ->and($toolCall->entities)->toBe(['ticket_id' => $ticket->id]);
});

test('a protocol of another unit, another condominium or unknown responds 404 ticket_not_found', function () {
    $otherUnitTicket = Ticket::factory()->for(Unit::factory()->for($this->condominium))->create();
    $otherCondominiumTicket = Ticket::factory()->for(Condominium::factory())->create(['protocol_number' => 777]);

    foreach ([$otherUnitTicket->protocol_number, $otherCondominiumTicket->protocol_number, 999] as $protocol) {
        $this->withToken($this->token)
            ->getJson(route('api.v1.tickets_show', ['protocol' => $protocol, 'phone' => '+5511999990000']))
            ->assertNotFound()
            ->assertJsonPath('code', 'ticket_not_found');
    }

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->latest('id')->first();

    expect($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::RECUSA))
        ->and($toolCall->error_code)->toBe('ticket_not_found');
});

test('a protocol that is not a protocol number responds 404 ticket_not_found and is logged', function (string $protocol) {
    $this->withToken($this->token)
        ->getJson('/api/v1/tickets/'.rawurlencode($protocol).'?phone=%2B5511999990000')
        ->assertNotFound()
        ->assertJsonPath('code', 'ticket_not_found');

    expect(AgentToolCall::query()->withoutGlobalScopes()->sole())
        ->agent_tool_id->toBe(AgentTool::idFor(AgentTool::TICKETS_SHOW))
        ->tool_call_result_id->toBe(ToolCallResult::idFor(ToolCallResult::RECUSA))
        ->resident_id->toBe($this->resident->id)
        ->http_status->toBe(404)
        ->error_code->toBe('ticket_not_found');
})->with([
    'not numeric' => ['abc'],
    'digits with letters' => ['4821abc'],
    'out of range' => ['9999999999'],
    'only the hash sign' => ['#'],
]);

test('a protocol prefixed with # finds the ticket', function () {
    $ticket = Ticket::factory()->for($this->resident)->create();

    $this->withToken($this->token)
        ->getJson('/api/v1/tickets/'.rawurlencode('#'.$ticket->protocol_number).'?phone=%2B5511999990000')
        ->assertOk()
        ->assertJsonPath('protocol', $ticket->protocol_number);
});

test('an unknown resident takes precedence over a non-numeric protocol', function () {
    $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_show', ['protocol' => 'abc', 'phone' => '+5511900000000']))
        ->assertForbidden()
        ->assertJsonPath('code', 'resident_not_found');
});

test('a non-numeric protocol without a token responds 401', function () {
    $this->getJson(route('api.v1.tickets_show', ['protocol' => 'abc', 'phone' => '+5511999990000']))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

test('unknown resident responds 403 on list and detail', function () {
    $ticket = Ticket::factory()->for($this->resident)->create();

    $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_list', ['phone' => '+5511900000000']))
        ->assertForbidden()
        ->assertJsonPath('code', 'resident_not_found');

    $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_show', ['protocol' => $ticket->protocol_number, 'phone' => '+5511900000000']))
        ->assertForbidden()
        ->assertJsonPath('code', 'resident_not_found');
});

test('phone is required on list and detail', function () {
    $ticket = Ticket::factory()->for($this->resident)->create();

    $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_list'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['phone']);

    $this->withToken($this->token)
        ->getJson(route('api.v1.tickets_show', ['protocol' => $ticket->protocol_number]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['phone']);
});
