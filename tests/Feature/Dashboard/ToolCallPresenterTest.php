<?php

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Block;
use App\Models\CommonArea;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\Resident;
use App\Models\RuleArticle;
use App\Models\Ticket;
use App\Models\ToolCallResult;
use App\Models\Unit;
use App\Support\ToolCallPresenter;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    $unit = Unit::factory()->for($this->condominium)->create([
        'number' => '402',
        'block_id' => Block::factory()->for($this->condominium)->create(['name' => 'B'])->id,
    ]);
    $this->resident = Resident::factory()->for($unit)->create(['name' => 'Marina Souza']);
});

/**
 * Entities of each kind referenced by the dataset, created in the test condominium.
 *
 * @return array<string, mixed>|null
 */
function presenterEntities(?string $kind, Condominium $condominium): ?array
{
    return match ($kind) {
        'article' => ['article_ids' => [
            RuleArticle::factory()->for($condominium)->create(['reference' => 'Art. 14'])->id,
            RuleArticle::factory()->for($condominium)->create(['reference' => 'Art. 22'])->id,
        ]],
        'no-articles' => ['article_ids' => []],
        'ticket' => ['ticket_id' => Ticket::factory()->for($condominium)->create(['protocol_number' => 4821])->id],
        'reservation' => ['reservation_id' => Reservation::factory()->create([
            'condominium_id' => $condominium->id,
            'common_area_id' => CommonArea::factory()->for($condominium)->create(['name' => 'Churrasqueira'])->id,
            'date' => '2026-09-20',
        ])->id],
        default => null,
    };
}

test('presents each tool and result as text and pill', function (string $tool, string $result, ?string $entityKind, string $text, string $pillText, string $variant) {
    $toolCall = AgentToolCall::factory()->{$result}()->create([
        'resident_id' => $this->resident->id,
        'agent_tool_id' => AgentTool::idFor($tool),
        'entities' => presenterEntities($entityKind, $this->condominium),
    ]);

    expect(app(ToolCallPresenter::class)->present($toolCall))->toBe([
        'who' => 'Marina · 402B',
        'text' => $text,
        'pill' => ['text' => $pillText, 'variant' => $variant],
    ]);
})->with([
    'rules_search sucesso' => [AgentTool::RULES_SEARCH, ToolCallResult::SUCESSO, 'article', 'consultou o regimento', 'Regimento · Art. 14', 'ia'],
    'rules_search vazio' => [AgentTool::RULES_SEARCH, ToolCallResult::VAZIO, 'no-articles', 'consultou o regimento sem resultado', 'Sem artigo', 'grey'],
    'rules_search recusa' => [AgentTool::RULES_SEARCH, ToolCallResult::RECUSA, null, 'consultou o regimento', 'Recusada', 'esc'],
    'notices_list sucesso' => [AgentTool::NOTICES_LIST, ToolCallResult::SUCESSO, null, 'consultou comunicados', 'Comunicados', 'ia'],
    'notices_list vazio' => [AgentTool::NOTICES_LIST, ToolCallResult::VAZIO, null, 'consultou comunicados', 'Comunicados', 'ia'],
    'tickets_create sucesso' => [AgentTool::TICKETS_CREATE, ToolCallResult::SUCESSO, 'ticket', 'abriu chamado', 'Chamado #4821', 'warn'],
    'tickets_create recusa' => [AgentTool::TICKETS_CREATE, ToolCallResult::RECUSA, null, 'abriu chamado', 'Recusada', 'esc'],
    'tickets_show sucesso' => [AgentTool::TICKETS_SHOW, ToolCallResult::SUCESSO, 'ticket', 'consultou status de chamado', 'Chamado #4821', 'grey'],
    'tickets_list sucesso' => [AgentTool::TICKETS_LIST, ToolCallResult::SUCESSO, null, 'consultou status de chamado', 'Chamados', 'grey'],
    'tickets_list vazio' => [AgentTool::TICKETS_LIST, ToolCallResult::VAZIO, null, 'consultou status de chamado', 'Chamados', 'grey'],
    'areas_list sucesso' => [AgentTool::AREAS_LIST, ToolCallResult::SUCESSO, null, 'consultou disponibilidade', 'Áreas', 'grey'],
    'areas_availability sucesso' => [AgentTool::AREAS_AVAILABILITY, ToolCallResult::SUCESSO, null, 'consultou disponibilidade', 'Áreas', 'grey'],
    'areas_availability recusa' => [AgentTool::AREAS_AVAILABILITY, ToolCallResult::RECUSA, null, 'consultou disponibilidade', 'Recusada', 'esc'],
    'reservations_create sucesso' => [AgentTool::RESERVATIONS_CREATE, ToolCallResult::SUCESSO, 'reservation', 'reservou churrasqueira 20/09', 'Reserva confirmada', 'ok'],
    'reservations_create recusa' => [AgentTool::RESERVATIONS_CREATE, ToolCallResult::RECUSA, null, 'tentou reservar área', 'Recusada', 'esc'],
    'reservations_list sucesso' => [AgentTool::RESERVATIONS_LIST, ToolCallResult::SUCESSO, null, 'consultou reservas', 'Reservas', 'grey'],
    'reservations_cancel sucesso' => [AgentTool::RESERVATIONS_CANCEL, ToolCallResult::SUCESSO, 'reservation', 'cancelou reserva', 'Reserva cancelada', 'grey'],
    'reservations_cancel recusa' => [AgentTool::RESERVATIONS_CANCEL, ToolCallResult::RECUSA, null, 'tentou cancelar reserva', 'Recusada', 'esc'],
    'escalations_create sucesso' => [AgentTool::ESCALATIONS_CREATE, ToolCallResult::SUCESSO, null, 'pediu atendimento humano', 'Escalado', 'esc'],
    'escalations_create recusa' => [AgentTool::ESCALATIONS_CREATE, ToolCallResult::RECUSA, null, 'pediu atendimento humano', 'Recusada', 'esc'],
    'residents_lookup sucesso' => [AgentTool::RESIDENTS_LOOKUP, ToolCallResult::SUCESSO, null, 'foi identificado', 'Verificação', 'grey'],
    'residents_lookup recusa' => [AgentTool::RESIDENTS_LOOKUP, ToolCallResult::RECUSA, null, 'foi identificado', 'Recusada', 'esc'],
]);

test('unidentified residents are presented by the formatted phone', function () {
    $toolCall = AgentToolCall::factory()->create([
        'condominium_id' => $this->condominium->id,
        'agent_tool_id' => AgentTool::idFor(AgentTool::RESIDENTS_LOOKUP),
        'phone' => '+5541998123344',
    ]);

    expect(app(ToolCallPresenter::class)->present($toolCall))->toBe([
        'who' => '+55 41 99812-3344',
        'text' => 'foi identificado',
        'pill' => ['text' => 'Verificação', 'variant' => 'grey'],
    ]);
});

test('calls without resident or phone are presented as an unidentified resident', function () {
    $toolCall = AgentToolCall::factory()->recusa()->create([
        'condominium_id' => $this->condominium->id,
        'agent_tool_id' => AgentTool::idFor(AgentTool::RESIDENTS_LOOKUP),
        'phone' => null,
    ]);

    expect(app(ToolCallPresenter::class)->present($toolCall)['who'])->toBe('Morador não identificado');
});

test('many calls are presented in the given order', function () {
    $ticketCall = AgentToolCall::factory()->create([
        'resident_id' => $this->resident->id,
        'agent_tool_id' => AgentTool::idFor(AgentTool::TICKETS_CREATE),
        'entities' => presenterEntities('ticket', $this->condominium),
    ]);
    $noticeCall = AgentToolCall::factory()->create([
        'resident_id' => $this->resident->id,
        'agent_tool_id' => AgentTool::idFor(AgentTool::NOTICES_LIST),
    ]);

    $presented = app(ToolCallPresenter::class)->presentMany(AgentToolCall::query()->whereKey([$noticeCall->id, $ticketCall->id])->latest('id')->get());

    expect(array_column(array_column($presented, 'pill'), 'text'))->toBe(['Comunicados', 'Chamado #4821']);
});
