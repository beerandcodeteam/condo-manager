<?php

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Escalation;
use App\Models\Resident;
use App\Models\Ticket;
use App\Services\Dashboard\DashboardMetricsService;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    $this->metrics = app(DashboardMetricsService::class);
});

/**
 * Moment in São Paulo local time, converted to the storage timezone.
 */
function saoPaulo(string $dateTime): CarbonImmutable
{
    return CarbonImmutable::parse($dateTime, 'America/Sao_Paulo')->utc();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function toolCallAt(Condominium $condominium, string $saoPauloDateTime, array $attributes = []): AgentToolCall
{
    return AgentToolCall::factory()->create([
        'condominium_id' => $condominium->id,
        'created_at' => saoPaulo($saoPauloDateTime),
        ...$attributes,
    ]);
}

test('a call at 23:30 in São Paulo counts as today and one at 00:30 of the next day does not', function () {
    $this->travelTo(saoPaulo('2026-09-15 23:50'));
    $late = Resident::factory()->for($this->condominium)->create();
    $nextDay = Resident::factory()->for($this->condominium)->create();

    toolCallAt($this->condominium, '2026-09-15 23:30', ['resident_id' => $late->id]);
    toolCallAt($this->condominium, '2026-09-16 00:30', ['resident_id' => $nextDay->id]);

    expect($this->metrics->attendancesToday($this->condominium))->toBe(1);

    $this->travelTo(saoPaulo('2026-09-16 09:00'));

    expect($this->metrics->attendancesToday($this->condominium))->toBe(1)
        ->and($this->metrics->attendancesYesterday($this->condominium))->toBe(1);
});

test('attendances count distinct residents and unidentified phones', function () {
    $this->travelTo(saoPaulo('2026-09-15 14:00'));
    [$helena, $carlos] = Resident::factory()->for($this->condominium)->count(2)->create();

    toolCallAt($this->condominium, '2026-09-15 09:00', ['resident_id' => $helena->id]);
    toolCallAt($this->condominium, '2026-09-15 09:05', ['resident_id' => $helena->id]);
    toolCallAt($this->condominium, '2026-09-15 10:00', ['resident_id' => $carlos->id]);
    toolCallAt($this->condominium, '2026-09-15 11:00', ['resident_id' => null, 'phone' => '+5541990000001']);
    toolCallAt($this->condominium, '2026-09-15 11:10', ['resident_id' => null, 'phone' => '+5541990000001']);
    toolCallAt($this->condominium, '2026-09-15 12:00', ['resident_id' => null, 'phone' => null]);

    expect($this->metrics->attendancesToday($this->condominium))->toBe(3);
});

test('delta percent is the rounded variation against yesterday', function () {
    $this->travelTo(saoPaulo('2026-09-15 14:00'));
    $residents = Resident::factory()->for($this->condominium)->count(4)->create();

    foreach ($residents->take(3) as $resident) {
        toolCallAt($this->condominium, '2026-09-14 10:00', ['resident_id' => $resident->id]);
    }

    foreach ($residents as $resident) {
        toolCallAt($this->condominium, '2026-09-15 10:00', ['resident_id' => $resident->id]);
    }

    expect($this->metrics->attendancesYesterday($this->condominium))->toBe(3)
        ->and($this->metrics->deltaPercent($this->condominium))->toBe(33);
});

test('delta percent is null when yesterday had no attendances', function () {
    $this->travelTo(saoPaulo('2026-09-15 14:00'));

    toolCallAt($this->condominium, '2026-09-15 10:00', ['resident_id' => Resident::factory()->for($this->condominium)]);

    expect($this->metrics->attendancesYesterday($this->condominium))->toBe(0)
        ->and($this->metrics->deltaPercent($this->condominium))->toBeNull();
});

test('resolved by the agent excludes residents escalated today and counts phone-only attendances', function () {
    $this->travelTo(saoPaulo('2026-09-15 14:00'));
    [$escalatedToday, $escalatedYesterday, $resolved] = Resident::factory()->for($this->condominium)->count(3)->create();

    toolCallAt($this->condominium, '2026-09-15 09:00', ['resident_id' => $escalatedToday->id]);
    toolCallAt($this->condominium, '2026-09-15 09:30', ['resident_id' => $escalatedYesterday->id]);
    toolCallAt($this->condominium, '2026-09-15 10:00', ['resident_id' => $resolved->id]);
    toolCallAt($this->condominium, '2026-09-15 11:00', ['resident_id' => null, 'phone' => '+5541990000001']);

    Escalation::factory()->for($escalatedToday)->create(['created_at' => saoPaulo('2026-09-15 09:01')]);
    Escalation::factory()->for($escalatedYesterday)->create(['created_at' => saoPaulo('2026-09-14 18:00')]);

    expect($this->metrics->resolvedByAgent($this->condominium))->toBe([
        'resolved' => 3,
        'total' => 4,
        'percent' => 75,
    ]);
});

test('resolved by the agent has a null percent without attendances', function () {
    $this->travelTo(saoPaulo('2026-09-15 14:00'));

    expect($this->metrics->resolvedByAgent($this->condominium))->toBe([
        'resolved' => 0,
        'total' => 0,
        'percent' => null,
    ]);
});

test('open tickets count aberto and em_andamento and urgent ones only those with priority alta', function () {
    Ticket::factory()->for($this->condominium)->aberto()->highPriority()->create();
    Ticket::factory()->for($this->condominium)->emAndamento()->highPriority()->create();
    Ticket::factory()->for($this->condominium)->aberto()->create();
    Ticket::factory()->for($this->condominium)->resolvido()->highPriority()->create();
    Ticket::factory()->for($this->condominium)->cancelado()->highPriority()->create();

    expect($this->metrics->openTickets($this->condominium))->toBe(3)
        ->and($this->metrics->urgentTickets($this->condominium))->toBe(2);
});

test('waiting escalations and their average wait in minutes', function () {
    $this->travelTo(saoPaulo('2026-09-15 14:00'));

    Escalation::factory()->for($this->condominium)->pendente()->create(['created_at' => now()->subMinutes(10)]);
    Escalation::factory()->for($this->condominium)->emAtendimento()->create(['created_at' => now()->subMinutes(21)]);
    Escalation::factory()->for($this->condominium)->resolvido()->create(['created_at' => now()->subHours(5)]);

    expect($this->metrics->waitingEscalations($this->condominium))->toBe(2)
        ->and($this->metrics->averageWaitMinutes($this->condominium))->toBe(16);
});

test('average wait is null when no escalation is waiting', function () {
    Escalation::factory()->for($this->condominium)->resolvido()->create();

    expect($this->metrics->waitingEscalations($this->condominium))->toBe(0)
        ->and($this->metrics->averageWaitMinutes($this->condominium))->toBeNull();
});

test('recent activity lists the 6 latest tool calls with resident, unit, tool and result', function () {
    $this->travelTo(saoPaulo('2026-09-15 14:00'));
    $resident = Resident::factory()->for($this->condominium)->create();

    $calls = collect(range(1, 7))->map(fn (int $minute) => toolCallAt($this->condominium, "2026-09-15 10:0{$minute}", [
        'resident_id' => $resident->id,
        'entities' => ['ticket_id' => $minute],
    ]));

    $activity = $this->metrics->recentActivity($this->condominium);

    expect($activity->pluck('id')->all())->toBe($calls->reverse()->take(6)->pluck('id')->values()->all())
        ->and($activity->first()->relationLoaded('resident'))->toBeTrue()
        ->and($activity->first()->resident->relationLoaded('unit'))->toBeTrue()
        ->and($activity->first()->agentTool->slug)->toBe(AgentTool::RULES_SEARCH)
        ->and($activity->first()->result->slug)->toBe('sucesso')
        ->and($activity->first()->entities)->toBe(['ticket_id' => 7]);
});

test('waiting top lists the 3 oldest unresolved escalations', function () {
    $this->travelTo(saoPaulo('2026-09-15 14:00'));

    $oldest = Escalation::factory()->for($this->condominium)->emAtendimento()->create(['created_at' => now()->subMinutes(90)]);
    $second = Escalation::factory()->for($this->condominium)->pendente()->create(['created_at' => now()->subMinutes(60)]);
    $third = Escalation::factory()->for($this->condominium)->pendente()->create(['created_at' => now()->subMinutes(30)]);
    Escalation::factory()->for($this->condominium)->pendente()->create(['created_at' => now()->subMinutes(5)]);
    Escalation::factory()->for($this->condominium)->resolvido()->create(['created_at' => now()->subHours(3)]);

    expect($this->metrics->waitingTop($this->condominium)->pluck('id')->all())->toBe([$oldest->id, $second->id, $third->id]);
});

test('resolution by tool is the share of sucesso in the last 7 days, grouping ticket status tools', function () {
    $this->travelTo(saoPaulo('2026-09-15 14:00'));
    $toolCall = fn (string $tool, string $state, int $daysAgo = 1) => AgentToolCall::factory()->{$state}()->create([
        'condominium_id' => $this->condominium->id,
        'agent_tool_id' => AgentTool::idFor($tool),
        'created_at' => now()->subDays($daysAgo),
    ]);

    $toolCall(AgentTool::RULES_SEARCH, 'sucesso');
    $toolCall(AgentTool::RULES_SEARCH, 'sucesso');
    $toolCall(AgentTool::RULES_SEARCH, 'vazio');
    $toolCall(AgentTool::RULES_SEARCH, 'vazio', daysAgo: 8);
    $toolCall(AgentTool::TICKETS_CREATE, 'recusa');
    $toolCall(AgentTool::TICKETS_LIST, 'sucesso');
    $toolCall(AgentTool::TICKETS_SHOW, 'sucesso');
    $toolCall(AgentTool::TICKETS_SHOW, 'recusa');
    $toolCall(AgentTool::TICKETS_SHOW, 'sucesso');
    $toolCall(AgentTool::RESERVATIONS_CREATE, 'sucesso');
    $toolCall(AgentTool::AREAS_LIST, 'recusa');

    expect($this->metrics->resolutionByTool7d($this->condominium))->toBe([
        ['label' => 'Regimento/convenção', 'percent' => 67],
        ['label' => 'Comunicados', 'percent' => null],
        ['label' => 'Abrir chamado', 'percent' => 0],
        ['label' => 'Status de chamado', 'percent' => 75],
        ['label' => 'Reservar área', 'percent' => 100],
    ]);
});

test('metrics without tool calls are zero or null', function () {
    $this->travelTo(saoPaulo('2026-09-15 14:00'));

    expect($this->metrics->attendancesToday($this->condominium))->toBe(0)
        ->and($this->metrics->recentActivity($this->condominium))->toBeEmpty()
        ->and(collect($this->metrics->resolutionByTool7d($this->condominium))->pluck('percent')->unique()->all())->toBe([null]);
});

test('metrics only consider the given condominium', function () {
    $this->travelTo(saoPaulo('2026-09-15 14:00'));
    $other = Condominium::factory()->create();
    $otherResident = Resident::factory()->for($other)->create();

    toolCallAt($other, '2026-09-15 09:00', ['resident_id' => $otherResident->id]);
    toolCallAt($other, '2026-09-14 09:00', ['resident_id' => $otherResident->id]);
    Escalation::factory()->for($otherResident)->create(['created_at' => now()->subMinutes(10)]);
    Ticket::factory()->for($other)->aberto()->highPriority()->create();

    $resident = Resident::factory()->for($this->condominium)->create();
    toolCallAt($this->condominium, '2026-09-15 10:00', ['resident_id' => $resident->id]);

    expect($this->metrics->attendancesToday($this->condominium))->toBe(1)
        ->and($this->metrics->attendancesYesterday($this->condominium))->toBe(0)
        ->and($this->metrics->resolvedByAgent($this->condominium))->toBe(['resolved' => 1, 'total' => 1, 'percent' => 100])
        ->and($this->metrics->openTickets($this->condominium))->toBe(0)
        ->and($this->metrics->urgentTickets($this->condominium))->toBe(0)
        ->and($this->metrics->waitingEscalations($this->condominium))->toBe(0)
        ->and($this->metrics->waitingTop($this->condominium))->toBeEmpty()
        ->and($this->metrics->recentActivity($this->condominium)->pluck('resident_id')->all())->toBe([$resident->id])
        ->and($this->metrics->resolutionByTool7d($this->condominium)[0]['percent'])->toBe(100);
});
