<?php

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Escalation;
use App\Models\EscalationReason;
use App\Models\EscalationStatus;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\ToolCallResult;
use App\Models\Unit;
use App\Models\WebhookDelivery;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Queue::fake();

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;
    $this->resident = Resident::factory()->for($this->condominium)->create(['phone' => '+5511999990000']);
});

test('creates a pendente escalation and responds 201 with id and status', function () {
    $this->withToken($this->token)
        ->postJson(route('api.v1.escalations_create'), [
            'phone' => '+55 11 99999-0000',
            'reason' => 'tool_recusou',
            'summary' => 'Quer o salão em 20/09 à noite; reservar_area recusou por conflito.',
            'ticket_protocol' => null,
        ])
        ->assertCreated()
        ->assertExactJson([
            'id' => Escalation::query()->withoutGlobalScopes()->sole()->id,
            'status' => 'pendente',
        ]);

    expect(Escalation::query()->withoutGlobalScopes()->sole())
        ->condominium_id->toBe($this->condominium->id)
        ->resident_id->toBe($this->resident->id)
        ->unit_id->toBe($this->resident->unit_id)
        ->ticket_id->toBeNull()
        ->escalation_status_id->toBe(EscalationStatus::idFor(EscalationStatus::PENDENTE))
        ->escalation_reason_id->toBe(EscalationReason::idFor(EscalationReason::TOOL_RECUSOU))
        ->summary->toBe('Quer o salão em 20/09 à noite; reservar_area recusou por conflito.')
        ->assigned_user_id->toBeNull()
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('an invalid reason responds 422 validation_error', function (mixed $reason) {
    $this->withToken($this->token)
        ->postJson(route('api.v1.escalations_create'), ['phone' => '+5511999990000', 'reason' => $reason, 'summary' => 'Quer falar com a síndica'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonValidationErrors(['reason']);

    expect(Escalation::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['urgente', '', null]);

test('summary and phone are required and ticket_protocol must be an integer', function () {
    $this->withToken($this->token)
        ->postJson(route('api.v1.escalations_create'), ['phone' => '+5511999990000', 'reason' => 'pediu_humano'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonValidationErrors(['summary']);

    $this->withToken($this->token)
        ->postJson(route('api.v1.escalations_create'), ['reason' => 'pediu_humano', 'summary' => 'Quer falar com a síndica', 'ticket_protocol' => 'abc'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['phone', 'ticket_protocol']);

    expect(Escalation::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('unknown or inactive resident responds 403 resident_not_found', function (string $phone) {
    Resident::factory()->inactive()->for($this->condominium)->create(['phone' => '+5511988887777']);
    Resident::factory()->for(Condominium::factory())->create(['phone' => '+5511977776666']);

    $this->withToken($this->token)
        ->postJson(route('api.v1.escalations_create'), ['phone' => $phone, 'reason' => 'pediu_humano', 'summary' => 'Quer falar com a síndica'])
        ->assertForbidden()
        ->assertJsonPath('code', 'resident_not_found');

    expect(Escalation::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['+5511900000000', '+5511988887777', '+5511977776666']);

test('ticket of another unit or nonexistent protocol responds 422 ticket_not_found', function (string $case) {
    $protocol = match ($case) {
        'another unit' => Ticket::factory()->for(Unit::factory()->for($this->condominium))->create(['condominium_id' => $this->condominium->id])->protocol_number,
        'another condominium' => Ticket::factory()->create()->protocol_number,
        'nonexistent' => 999,
        'out of range' => 99999999999,
    };

    $this->withToken($this->token)
        ->postJson(route('api.v1.escalations_create'), [
            'phone' => '+5511999990000',
            'reason' => 'sem_regra',
            'summary' => 'Pergunta sobre o chamado',
            'ticket_protocol' => $protocol,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'ticket_not_found');

    expect(Escalation::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['another unit', 'another condominium', 'nonexistent', 'out of range']);

test('a valid ticket of the resident unit is linked', function () {
    $ticket = Ticket::factory()->for($this->resident)->create();

    $this->withToken($this->token)
        ->postJson(route('api.v1.escalations_create'), [
            'phone' => '+5511999990000',
            'reason' => 'pediu_humano',
            'summary' => 'Chamado do elevador parado há dias',
            'ticket_protocol' => $ticket->protocol_number,
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'pendente');

    expect(Escalation::query()->withoutGlobalScopes()->sole()->ticket_id)->toBe($ticket->id);
});

test('logs sucesso with entities.escalation_id and the resident', function () {
    $this->withToken($this->token)
        ->postJson(route('api.v1.escalations_create'), ['phone' => '+5511999990000', 'reason' => 'pediu_humano', 'summary' => 'Quer falar com a síndica'])
        ->assertCreated();

    $escalation = Escalation::query()->withoutGlobalScopes()->sole();
    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->agent_tool_id)->toBe(AgentTool::idFor(AgentTool::ESCALATIONS_CREATE))
        ->and($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO))
        ->and($toolCall->http_status)->toBe(201)
        ->and($toolCall->resident_id)->toBe($this->resident->id)
        ->and($toolCall->entities)->toBe(['escalation_id' => $escalation->id]);
});
