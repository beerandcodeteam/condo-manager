<?php

use App\Models\Condominium;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
});

test('overview numbers match the metrics service for the demo scenario', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 14:00:00', 'America/Sao_Paulo'));
    $this->seed(DemoSeeder::class);

    $aurora = Condominium::where('name', 'Residencial Aurora')->sole();
    $metrics = app(DashboardMetricsService::class);
    $delta = $metrics->deltaPercent($aurora);
    $resolved = $metrics->resolvedByAgent($aurora);
    $waiting = $metrics->waitingTop($aurora);

    $response = $this->actingAs(User::where('email', 'renata@example.com')->sole())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<title>Visão geral · Síndico Conversacional</title>', false)
        ->assertSee('wire:poll.60s', false)
        ->assertSeeInOrder([
            'Atendimentos hoje', (string) $metrics->attendancesToday($aurora), $delta === null ? '—' : sprintf('%+d%% vs. ontem', $delta),
            'Resolvidas pelo agente', $resolved['percent'].'%', "{$resolved['resolved']} de {$resolved['total']} sem humano",
            'Chamados abertos', (string) $metrics->openTickets($aurora), "{$metrics->urgentTickets($aurora)} urgentes",
            'Aguardando síndico', (string) $metrics->waitingEscalations($aurora), "tempo médio {$metrics->averageWaitMinutes($aurora)} min",
        ])
        ->assertSeeInOrder(['Atividade do agente', 'Marina · 402B', 'consultou o regimento', 'Regimento · Art. 14', 'Carlos · 1201A', 'abriu chamado', 'Chamado #4821'])
        ->assertSeeInOrder(['Aguardando humano', ...$waiting->map(fn ($escalation) => "{$escalation->resident->name} · {$escalation->unit->label}")->all()])
        ->assertSeeInOrder(collect($metrics->resolutionByTool7d($aurora))
            ->flatMap(fn (array $bar): array => [$bar['label'], $bar['percent'] === null ? '—' : "{$bar['percent']}%"])
            ->all());

    $html = $response->getContent();

    expect($metrics->openTickets($aurora))->toBe(4)
        ->and($metrics->urgentTickets($aurora))->toBe(2)
        ->and($metrics->waitingEscalations($aurora))->toBe(3)
        ->and(substr_count($html, 'data-activity-line'))->toBe(6)
        ->and(substr_count($html, 'data-waiting-escalation="'))->toBe(3)
        ->and(substr_count($html, 'data-resolution-bar'))->toBe(5);
});

test('each panel role opens the overview of its condominium', function (string $state) {
    $condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $user = User::factory()->{$state}()->for($condominium)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<h1 class="min-w-0 flex-1 truncate text-[17px] font-semibold tracking-[-0.01em]">Visão geral</h1>', false)
        ->assertSee('Atendimentos hoje')
        ->assertSee('Residencial Aurora');
})->with(['sindico', 'zelador']);

test('super admin sees the overview of the selected condominium', function () {
    [$aurora, $serena] = Condominium::factory()->count(2)->create();
    Ticket::factory()->for($serena)->aberto()->count(2)->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->withSession(['current_condominium_id' => $serena->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Chamados abertos', '2', 'nenhum urgente']);
});

test('overview renders a queue link and no conversations link', function () {
    $user = User::factory()->zelador()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('href="'.route('escalations.index').'" class="text-accent hover:underline" data-waiting-queue-link>Ver fila</a>', false)
        ->assertDontSee('Ver conversas')
        ->assertDontSee('Conversas hoje');
});

test('overview without activity shows empty states and dashes', function () {
    $user = User::factory()->sindico()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Atendimentos hoje', '0', '—', 'Resolvidas pelo agente', '—', '0 de 0 sem humano'])
        ->assertSee('Nenhuma atividade do agente ainda.')
        ->assertSee('Ninguém aguardando atendimento.');
});

test('dashboard route is protected by the dashboard.view gate', function () {
    expect(app('router')->getRoutes()->getByName('dashboard')->gatherMiddleware())
        ->toContain('can:dashboard.view');
});
